<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Support\Pay2SConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payment\Entities\PartnerPay2sLinkSession;
use Modules\Payment\Entities\PartnerSePayAccount;
use RuntimeException;

/**
 * Đối tác tự liên kết ngân hàng với Pay2S ngay trên web/app của mình (Partner API — BankHub Hosted Link, docs.pay2s.vn/partner).
 * Backend tạo phiên → app mở hosted_link_url (webview/tab mới) → đối tác nhập thông tin + OTP TRÊN TRANG PAY2S (mình không chạm OTP) →
 * app hỏi trạng thái phiên; khi "connected" thì tự ghi tài khoản vào partner_sepay_accounts và tạo webhook nhận tiền (lấy token webhook).
 */
class Pay2SBankHubService
{
    public function configured(): bool
    {
        return filled(Pay2SConfig::get('partner_access_key')) && filled(Pay2SConfig::get('partner_secret_key'));
    }

    /** @return array{ok: bool, message: string} Nút "Kiểm tra khoá Partner API" ở CMS: thử xin token bằng Access/Secret Key đang dùng. */
    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Chưa nhập Access Key / Secret Key của Partner API.'];
        }

        Cache::forget($this->tokenCacheKey());

        try {
            $this->accessToken();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'message' => 'Kết nối Partner API Pay2S thành công (' . $this->baseUrl() . ').'];
    }

    private function tokenCacheKey(): string
    {
        return 'pay2s.partner.token.' . md5($this->baseUrl() . Pay2SConfig::get('partner_access_key') . Pay2SConfig::get('partner_secret_key'));
    }

    private function baseUrl(): string
    {
        return rtrim((string) Pay2SConfig::get('partner_api_url', 'https://api-partner.pay2s.vn'), '/');
    }

    private function accessToken(): string
    {
        return Cache::remember($this->tokenCacheKey(), 3000, function () {
            $response = Http::withBasicAuth((string) Pay2SConfig::get('partner_access_key'), (string) Pay2SConfig::get('partner_secret_key'))
                ->acceptJson()->timeout(15)->post($this->baseUrl() . '/v1/auth/authorize');

            $token = $response->json('data.access_token') ?? $response->json('access_token') ?? $response->json('data.token') ?? $response->json('token');

            if (! $response->successful() || ! filled($token)) {
                throw new RuntimeException('Pay2S từ chối khoá Partner API (Access Key / Secret Key sai hoặc chưa kích hoạt BankHub).');
            }

            return (string) $token;
        });
    }

    private function http()
    {
        return Http::withToken($this->accessToken())->acceptJson()->asJson()->timeout(15);
    }

    /** Mã khách hàng gửi cho Pay2S: cố định theo đối tác để Pay2S gom các tài khoản đã liên kết của đối tác đó. */
    public function customerReference(Partner $partner): string
    {
        return 'partner-' . $partner->id;
    }

    private function parentOrigin(): string
    {
        $origin = (string) (Pay2SConfig::get('partner_parent_origin') ?: config('app.url'));
        $parts = parse_url($origin);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Lý do KHÔNG tạo được phiên liên kết cho đối tác (null = được). Dùng chung cho API admin và trang "Liên kết ngân hàng Pay2S" của đối tác.
     */
    public function startBlockedReason(Partner $partner): ?string
    {
        if (\Modules\Payment\Entities\PartnerPayOsAccount::query()->where('partner_id', $partner->id)->where('is_active', true)->exists()) {
            return 'Đối tác đang dùng kênh PayOS riêng. Tắt kênh PayOS trước khi liên kết Pay2S (mỗi đối tác chỉ dùng một kênh).';
        }

        if (! $this->configured()) {
            return 'Chưa cấu hình khoá Partner API của Pay2S. Liên hệ 365 HOME để bật tính năng liên kết ngân hàng.';
        }

        return null;
    }

    // ───────────────────────── Liên kết trực tiếp bằng API (ngân hàng OpenAPI: nhập thông tin + OTP ngay trong app) ─────────────────────────

    /**
     * Ngân hàng liên kết được bằng API trực tiếp (bank.html của Pay2S — chỉ loại OpenAPI, KHÔNG cần mật khẩu ngân hàng). Ngân hàng RPA (Vietcombank, VietinBank, Techcombank...)
     * cần tên đăng nhập + mật khẩu nên không thu ở đây — dùng Hosted Link của Pay2S (đối tác nhập trực tiếp trên khung của Pay2S).
     *
     * @var array<string, array{label: string, pay2s: string, types: list<string>, cccd: bool, merchant: bool, username_business: bool}>
     */
    public const OPENAPI_BANKS = [
        'ACB' => ['label' => 'ACB', 'pay2s' => 'ACB', 'types' => ['personal', 'business'], 'cccd' => false, 'merchant' => false, 'username_business' => true],
        'MB' => ['label' => 'MB Bank', 'pay2s' => 'MBB', 'types' => ['personal', 'business'], 'cccd' => true, 'merchant' => false, 'username_business' => false],
        'BIDV' => ['label' => 'BIDV', 'pay2s' => 'BIDV', 'types' => ['personal'], 'cccd' => true, 'merchant' => true, 'username_business' => false],
    ];

    private function directApiCacheKey(): string
    {
        return 'pay2s.direct_bank_api.' . md5($this->baseUrl() . Pay2SConfig::get('partner_access_key'));
    }

    /** Pay2S từ chối vì ứng dụng chưa có quyền dùng API này? (mã 401/403 hoặc thông báo "không có quyền"). */
    private function isPermissionDenied(\Illuminate\Http\Client\Response $response): bool
    {
        // `message` có thể là mảng (danh sách tài khoản của GET /v1/banks): chỉ xét khi là chuỗi.
        $message = $response->json('message');
        $message = is_string($message) ? $message : (is_string($response->json('error.message')) ? $response->json('error.message') : '');

        return ! $response->successful() && (in_array($response->status(), [401, 403], true) || str_contains(Str::lower(Str::ascii($message)), 'khong co quyen'));
    }

    /**
     * Pay2S đã bật API nhập thông tin + OTP trực tiếp (POST /v1/banks) cho ứng dụng này chưa? Hỏi nhẹ bằng GET /v1/banks và nhớ kết quả 10 phút; lỗi mạng / không rõ thì coi là CÓ
     * (không nhớ) để không ẩn form oan. Chưa bật thì trang chỉ hiện nút mở cửa sổ liên kết của Pay2S (Hosted Link), tránh bắt đối tác nhập hai lần.
     */
    public function directBankApiAvailable(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $cached = Cache::get($this->directApiCacheKey());

        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = $this->http()->get($this->baseUrl() . '/v1/banks');
        } catch (\Throwable) {
            return true;
        }

        if ($this->isPermissionDenied($response)) {
            Cache::put($this->directApiCacheKey(), false, 600);

            return false;
        }

        if ($response->successful()) {
            Cache::put($this->directApiCacheKey(), true, 600);
        }

        return true;
    }

    /**
     * Tài khoản này đã được thêm ở Pay2S chưa (my.pay2s.vn hay lần liên kết trước)? Trả dòng của Pay2S (id, accountNumber, vaNumber, status...) hoặc null. Đã có thì KHÔNG thêm lần nữa
     * (Pay2S báo "đã tồn tại") — chỉ cần lưu tài khoản cho đối tác + tạo webhook, không phải nhập OTP.
     *
     * @return array<string,mixed>|null
     */
    public function existingBank(string $accountNumber): ?array
    {
        try {
            $response = $this->http()->get($this->baseUrl() . '/v1/banks');
        } catch (\Throwable) {
            return null;
        }

        $rows = $response->json('message');
        $rows = is_array($rows) ? $rows : (array) ($response->json('data') ?? []);
        $number = trim($accountNumber);

        $row = collect($rows)->filter(fn ($r) => is_array($r))->first(fn ($r) => (string) ($r['accountNumber'] ?? '') === $number || (string) ($r['vaNumber'] ?? '') === $number);

        return $response->successful() && $row ? $row : null;
    }

    /** Thông báo của Pay2S cho biết thông tin ngân hàng đã tồn tại (đã thêm trước đó). */
    public static function isAlreadyExistsMessage(string $message): bool
    {
        $m = Str::lower(Str::ascii($message));

        return str_contains($m, 'existed') || str_contains($m, 'da ton tai') || str_contains($m, 'already exist');
    }

    /** Body POST /v1/banks và /v1/banks/confirm-otp từ dữ liệu đối tác nhập. @param array<string,mixed> $in */
    private function openApiPayload(array $in, bool $confirm = false): array
    {
        $cfg = self::OPENAPI_BANKS[$in['bank']];
        $payload = ['type' => 'openapi', 'bankShortName' => $cfg['pay2s'], 'accountNumber' => trim((string) $in['account_number'])];

        if (filled($in['merchant_id'] ?? null) && $cfg['merchant']) {
            $payload['merchantId'] = trim((string) $in['merchant_id']);
        }
        if (filled($in['username'] ?? null) && $cfg['username_business'] && ($in['account_type'] ?? 'personal') === 'business') {
            $payload['username'] = trim((string) $in['username']);
        }

        if ($confirm) {
            return $payload;
        }

        $payload += [
            'accName' => Str::upper(Str::ascii(trim((string) $in['account_holder']))),
            'accMobile' => preg_replace('/\s+/', '', (string) $in['phone']),
            'accEmail' => trim((string) $in['email']),
        ];

        if (($in['account_type'] ?? 'personal') === 'business') {
            $payload['bank_type'] = 'business';
        }
        if (filled($in['cccd'] ?? null) && $cfg['cccd']) {
            $payload['cccd'] = trim((string) $in['cccd']);
        }

        return $payload;
    }

    /**
     * Bước 1: gửi thông tin ngân hàng cho Pay2S; ngân hàng gửi OTP về số điện thoại đăng ký với ngân hàng.
     *
     * @param array<string,mixed> $in bank (mã trong OPENAPI_BANKS), account_type, account_number, account_holder, phone, email, cccd?, merchant_id?, username?
     * @return array{ok: bool, needs_otp: bool, permission: bool, message: string}
     */
    public function addOpenApiBank(array $in): array
    {
        return $this->bankCall('/v1/banks', $this->openApiPayload($in), true);
    }

    /** Bước 2: gửi OTP đối tác vừa nhận. Cùng các trường định danh như bước 1 + otp. @return array{ok: bool, needs_otp: bool, message: string} */
    public function confirmOpenApiBankOtp(array $in, string $otp): array
    {
        return $this->bankCall('/v1/banks/confirm-otp', $this->openApiPayload($in, true) + ['otp' => trim($otp)], false);
    }

    /** @return array{ok: bool, needs_otp: bool, permission: bool, message: string} permission = Pay2S từ chối vì ứng dụng chưa có quyền dùng API này */
    private function bankCall(string $path, array $payload, bool $add): array
    {
        try {
            $response = $this->http()->post($this->baseUrl() . $path, $payload);
        } catch (\Throwable $e) {
            Log::warning('Pay2S BankHub: lỗi gọi ' . $path, ['error' => $e->getMessage()]);

            return ['ok' => false, 'needs_otp' => false, 'permission' => false, 'message' => 'Không kết nối được Pay2S: ' . Str::limit($e->getMessage(), 150)];
        }

        $json = $response->json() ?? [];
        $message = $json['message'] ?? $json['error']['message'] ?? $json['error'] ?? '';
        $message = is_array($message) ? Str::limit(json_encode($message, JSON_UNESCAPED_UNICODE), 200) : (string) $message;
        $ok = $response->successful() && ($json['status'] ?? $json['success'] ?? true) !== false;

        if (! $ok) {
            // Không ghi mật khẩu / OTP vào log.
            Log::warning('Pay2S BankHub ' . $path . ' thất bại', ['status' => $response->status(), 'message' => Str::limit($message, 200)]);
        }

        $otp = (int) ($json['OTP'] ?? $json['otp'] ?? Arr::get($json, 'data.OTP', Arr::get($json, 'data.otp', 0))) === 1;

        // Ứng dụng chưa được Pay2S cấp quyền cho API này (vd "Tài khoản không có quyền sử dụng BankHub"): nơi gọi chuyển sang Hosted Link.
        $permission = ! $ok && $this->isPermissionDenied($response);

        if ($permission && $add) {
            Cache::put($this->directApiCacheKey(), false, 600);
        }

        return ['ok' => $ok, 'needs_otp' => $add && $ok && $otp, 'permission' => $permission, 'message' => $message !== '' ? $message : ($ok ? 'OK' : 'Pay2S từ chối yêu cầu (mã ' . $response->status() . ').')];
    }

    /**
     * Sau khi Pay2S báo xong: hỏi lại danh sách tài khoản (không tin thông báo "hợp lệ"), tìm tài khoản vừa thêm đang hoạt động, tạo webhook nhận tiền rồi ghi tài khoản cho đối tác.
     * Số lưu để khách chuyển khoản là SỐ TÀI KHOẢN ẢO (VA) nếu Pay2S trả về — với OpenAPI, tiền phải vào VA thì mới có biến động số dư.
     *
     * @param array<string,mixed> $in như addOpenApiBank
     * @return array{ok: bool, message: string, account: ?PartnerSePayAccount}
     */
    public function finalizeOpenApiBank(Partner $partner, ?int $categoryId, array $in): array
    {
        $response = $this->http()->get($this->baseUrl() . '/v1/banks');
        $rows = $response->json('message');
        $rows = is_array($rows) ? $rows : (array) ($response->json('data') ?? []);
        $number = trim((string) $in['account_number']);

        $row = collect($rows)->filter(fn ($r) => is_array($r))->first(fn ($r) => (string) ($r['accountNumber'] ?? '') === $number || (string) ($r['vaNumber'] ?? '') === $number);

        if (! $row) {
            return ['ok' => false, 'message' => 'Pay2S chưa ghi nhận tài khoản này. Đợi vài giây rồi bấm "Kiểm tra liên kết", hoặc thử lại.', 'account' => null];
        }

        if ((int) ($row['status'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Tài khoản đã thêm nhưng Pay2S chưa bật hoạt động (' . ($row['statusText'] ?? 'chưa rõ') . '). Kiểm tra lại ở my.pay2s.vn hoặc liên hệ 365 Home.', 'account' => null];
        }

        $receiving = trim((string) (($row['vaNumber'] ?? '') ?: ($row['accountNumber'] ?? $number)));

        if ($this->numberTaken((string) $partner->id, $categoryId, $receiving)) {
            return ['ok' => false, 'message' => 'Số tài khoản này đã gắn cho một đối tác hoặc chi nhánh khác.', 'account' => null];
        }

        $bank = $this->matchBank((string) self::OPENAPI_BANKS[$in['bank']]['pay2s']);
        $token = $this->createWebhook((string) ($row['id'] ?? ''));
        $existing = PartnerSePayAccount::query()->where('partner_id', $partner->id)->where('category_id', $categoryId)->first();

        $values = [
            'provider' => PartnerSePayAccount::PROVIDER_PAY2S,
            'account_number' => $receiving,
            'account_holder' => Str::upper(Str::ascii(trim((string) $in['account_holder']))) ?: 'CHUA RO',
            'is_active' => true,
        ] + ($bank ? ['bank_code' => $bank['code'], 'bank_bin' => $bank['bin']] : []);

        if ($token) {
            $values['webhook_token'] = $token;
        }

        $account = PartnerSePayAccount::query()->updateOrCreate(['partner_id' => $partner->id, 'category_id' => $categoryId], $values);

        // Vừa lưu tài khoản là đăng ký webhook ngay: bước tạo ở trên không ra token thì thử lại bằng đường tra cứu theo số tài khoản.
        $hook = filled($account->webhook_token) ? ['ok' => true, 'message' => ''] : $this->ensureWebhook($account);

        return [
            'ok' => true,
            'account' => $account->fresh(),
            'message' => $hook['ok'] ? 'Đã liên kết ngân hàng và tạo webhook nhận tiền.' : 'Đã liên kết ngân hàng nhưng chưa tạo được webhook' . ($hook['message'] !== '' ? ': ' . $hook['message'] : '.') . ' Bấm "Lấy token tự động" khi sẵn sàng (máy chủ có địa chỉ công khai) hoặc dán token Pay2S cấp.',
        ];
    }

    /**
     * Tự đăng ký webhook ngay sau khi lưu tài khoản Pay2S: chỉ làm khi là cổng Pay2S, đã có khoá Partner API và CHƯA có token. Mọi lỗi trả về trong kết quả, không ném ra ngoài
     * để việc lưu tài khoản không bao giờ bị hỏng vì Pay2S.
     *
     * @return array{ok: bool, created: bool, message: string}
     */
    public function ensureWebhook(PartnerSePayAccount $account): array
    {
        if ($account->provider !== PartnerSePayAccount::PROVIDER_PAY2S || filled($account->webhook_token) || ! $this->configured()) {
            return ['ok' => filled($account->webhook_token), 'created' => false, 'message' => ''];
        }

        try {
            return $this->provisionWebhook($account);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'created' => false, 'message' => 'Không đăng ký được webhook: ' . Str::limit($e->getMessage(), 150)];
        }
    }

    /**
     * Lấy token webhook "Nhận tiền" TỰ ĐỘNG cho một tài khoản đã liên kết ở Pay2S (liên kết trên my.pay2s.vn hay qua BankHub đều được): tìm tài khoản trong danh sách của Pay2S theo số
     * tài khoản, kiểm tra đã có webhook chưa, chưa có thì tạo (POST /v1/webhooks) và LƯU token vào $account. Pay2S chỉ trả token MỘT lần lúc tạo nên nếu webhook đã có mà hệ thống không giữ
     * token thì không lấy lại được — báo rõ để xoá/tạo lại ở Pay2S, hệ thống không tự xoá webhook nào.
     *
     * @param string|null $webhookUrl mặc định theo cấu hình (PAY2S_WEBHOOK_URL hoặc route webhook.pay2s). Pay2S gọi từ internet nên địa chỉ nội bộ (.test) có thể bị từ chối.
     * @return array{ok: bool, created: bool, message: string}
     */
    public function provisionWebhook(PartnerSePayAccount $account, ?string $webhookUrl = null): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'created' => false, 'message' => 'Chưa cấu hình khoá Partner API của Pay2S (CMS → Pay2S — cấu hình chung).'];
        }

        $url = $webhookUrl ?: (string) (Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s'));
        $number = trim((string) $account->account_number);

        try {
            $banks = $this->http()->get($this->baseUrl() . '/v1/banks');
            $rows = $banks->json('message');
            $rows = is_array($rows) ? $rows : (array) ($banks->json('data') ?? []);

            if (! $banks->successful()) {
                return ['ok' => false, 'created' => false, 'message' => 'Pay2S không cho đọc danh sách tài khoản (mã ' . $banks->status() . ').'];
            }

            $bank = collect($rows)->filter(fn ($r) => is_array($r))->first(fn ($r) => (string) ($r['accountNumber'] ?? '') === $number || (string) ($r['vaNumber'] ?? '') === $number);

            if (! $bank || blank($bank['id'] ?? null)) {
                return ['ok' => false, 'created' => false, 'message' => 'Không thấy số tài khoản ' . $number . ' trong danh sách ngân hàng đã liên kết ở Pay2S. Liên kết tài khoản này ở Pay2S trước.'];
            }

            if ((int) ($bank['status'] ?? 1) !== 1) {
                return ['ok' => false, 'created' => false, 'message' => 'Tài khoản này đang không hoạt động ở Pay2S (' . ($bank['statusText'] ?? 'chưa rõ') . ').'];
            }

            $hooks = $this->http()->get($this->baseUrl() . '/v1/webhooks');
            $existing = collect((array) ($hooks->json('data') ?? $hooks->json('message') ?? []))->filter(fn ($w) => is_array($w))
                ->first(fn ($w) => (string) ($w['user_bank_id'] ?? '') === (string) $bank['id'] && (string) ($w['webhook_url'] ?? $w['url'] ?? '') === $url);

            if ($existing) {
                return ['ok' => filled($account->webhook_token), 'created' => false, 'message' => filled($account->webhook_token)
                    ? 'Webhook đã có ở Pay2S và hệ thống đã giữ token.'
                    : 'Pay2S đã có webhook cho tài khoản này nhưng token chỉ hiện một lần lúc tạo nên không lấy lại được. Xoá webhook đó ở my.pay2s.vn → Webhooks rồi bấm lại để tạo mới.'];
            }

            $response = $this->http()->post($this->baseUrl() . '/v1/webhooks', ['user_bank_id' => (string) $bank['id'], 'webhook_url' => $url, 'direction' => 'IN', 'type' => 'IN']);
        } catch (\Throwable $e) {
            Log::warning('Pay2S BankHub: lỗi tạo webhook', ['error' => $e->getMessage()]);

            return ['ok' => false, 'created' => false, 'message' => 'Không kết nối được Pay2S: ' . Str::limit($e->getMessage(), 150)];
        }

        $message = $response->json('message') ?? $response->json('error.message') ?? '';
        $message = is_string($message) ? $message : '';

        if (! $response->successful() || ($response->json('success') ?? $response->json('status')) === false) {
            Log::warning('Pay2S BankHub tạo webhook thất bại', ['status' => $response->status(), 'message' => Str::limit($message, 200)]);

            return ['ok' => false, 'created' => false, 'message' => 'Pay2S từ chối tạo webhook (' . $response->status() . ')' . ($message !== '' ? ': ' . Str::limit($message, 200) : '') . '.'];
        }

        $token = $response->json('data.webhook_token') ?? $response->json('data.token') ?? $response->json('data.secret') ?? $response->json('webhook_token') ?? $response->json('token');

        if (! filled($token)) {
            return ['ok' => false, 'created' => true, 'message' => 'Pay2S đã tạo webhook nhưng không trả token trong phản hồi. Mở my.pay2s.vn → Webhooks để xem, hoặc xoá và tạo lại.'];
        }

        $account->forceFill(['webhook_token' => (string) $token])->save();

        return ['ok' => true, 'created' => true, 'message' => 'Đã tạo webhook nhận tiền và lưu token.'];
    }

    /** Số tài khoản đã gắn ở chỗ KHÁC (đối tác khác, hoặc cùng đối tác nhưng phạm vi khác: mặc định / chi nhánh khác). */
    public function numberTaken(string $partnerId, ?int $categoryId, string $number): bool
    {
        return PartnerSePayAccount::query()->where('account_number', $number)->where(function ($q) use ($partnerId, $categoryId) {
            $q->where('partner_id', '!=', $partnerId)->orWhere(fn ($same) => $categoryId === null
                ? $same->whereNotNull('category_id')
                : $same->whereNull('category_id')->orWhere('category_id', '!=', $categoryId));
        })->exists();
    }

    /** Tạo phiên Hosted Link (15 phút). @throws RuntimeException khi Pay2S lỗi / chưa cấu hình. */
    public function createSession(Partner $partner, ?string $userId = null, ?int $categoryId = null): PartnerPay2sLinkSession
    {
        if (! $this->configured()) {
            throw new RuntimeException('Chưa cấu hình khoá Partner API của Pay2S (PAY2S_PARTNER_ACCESS_KEY / PAY2S_PARTNER_SECRET_KEY).');
        }

        $reference = $this->customerReference($partner);
        $response = $this->http()->post($this->baseUrl() . '/v1/bankhub/link-sessions', [
            'customer_reference' => $reference,
            'purpose' => 'LINK_BANK_ACCOUNT',
            'parent_origin' => $this->parentOrigin(),
            'state' => Str::random(32),
        ]);

        $sessionId = $response->json('data.session_id');

        if (! $response->successful() || ! $sessionId) {
            Log::warning('Pay2S BankHub tạo phiên liên kết thất bại', ['partner_id' => $partner->id, 'status' => $response->status(), 'body' => Str::limit($response->body(), 500)]);

            throw new RuntimeException('Pay2S không tạo được phiên liên kết: ' . Str::limit((string) ($response->json('message') ?: $response->json('error.message') ?: 'lỗi không rõ'), 200));
        }

        return PartnerPay2sLinkSession::query()->create([
            'partner_id' => $partner->id,
            'category_id' => $categoryId,
            'session_id' => (string) $sessionId,
            'customer_reference' => $reference,
            'status' => 'created',
            'hosted_link_url' => $response->json('data.hosted_link_url'),
            'expires_at' => now()->addSeconds((int) ($response->json('data.expires_in') ?: 900)),
            'created_by' => $userId,
        ]);
    }

    /**
     * Hỏi Pay2S trạng thái phiên; connected thì ghi tài khoản + tạo webhook (một lần). Gọi lặp lại an toàn.
     */
    public function refresh(PartnerPay2sLinkSession $session): PartnerPay2sLinkSession
    {
        if ($session->applied || in_array($session->status, PartnerPay2sLinkSession::FAILED, true)) {
            return $session;
        }

        $response = $this->http()->get($this->baseUrl() . '/v1/bankhub/link-sessions/' . $session->session_id);

        if (! $response->successful()) {
            return $session;
        }

        $body = $response->json('data') ?? $response->json();
        $status = (string) ($body['status'] ?? $session->status);
        $session->forceFill([
            'status' => $status,
            'account' => ($body['account'] ?? null) ?: $session->account,
            'error_code' => Arr::get($body, 'error.code'),
            'error_message' => Str::limit((string) Arr::get($body, 'error.message', ''), 250) ?: null,
        ])->save();

        if ($status === 'connected') {
            $this->apply($session);
        } elseif ($status === 'created' && $session->expires_at?->isPast()) {
            $session->forceFill(['status' => 'expired'])->save();
        }

        return $session->refresh();
    }

    /** Ghi tài khoản vừa liên kết cho đối tác và tạo webhook nhận tiền. Không đè lên tài khoản SePay/PayOS đang dùng cổng khác một cách âm thầm. */
    private function apply(PartnerPay2sLinkSession $session): void
    {
        $account = (array) $session->account;
        $number = trim((string) ($account['account_number'] ?? $account['number'] ?? ''));

        if ($number === '') {
            $account = $this->lookupAccount($session) ?? [];
            $number = trim((string) ($account['account_number'] ?? $account['number'] ?? ''));
        }

        if ($number === '') {
            Log::warning('Pay2S BankHub: phiên connected nhưng không đọc được số tài khoản', ['session' => $session->session_id]);

            return;
        }

        $bank = $this->matchBank((string) ($account['bank_code'] ?? $account['bank_short_name'] ?? $account['bank_name'] ?? $account['bank'] ?? ''));

        $existing = PartnerSePayAccount::query()->where('partner_id', $session->partner_id)->where('category_id', $session->category_id)->first();
        // Số tài khoản đã dùng ở chỗ KHÁC (đối tác khác, hoặc cùng đối tác nhưng phạm vi khác: mặc định / chi nhánh khác) thì không gắn thêm được.
        $taken = $this->numberTaken((string) $session->partner_id, $session->category_id, $number);

        if ($taken) {
            // Pay2S đã liên kết xong (connected) nhưng số tài khoản đang gắn ở chỗ khác trong hệ thống: KHÔNG đánh dấu failed — giữ phiên connected để sau khi gỡ dòng cũ
            // bấm "Hoàn tất lưu tài khoản" (refresh) là lưu được, không phải liên kết lại ngân hàng.
            $session->forceFill(['error_code' => 'ACCOUNT_TAKEN', 'error_message' => 'Số tài khoản ' . substr($number, -4) . ' (đuôi) đã gắn cho một đối tác hoặc chi nhánh khác trong hệ thống 365 Home — gỡ dòng cũ đó rồi bấm "Hoàn tất lưu tài khoản".'])->save();

            return;
        }

        $values = [
            'provider' => PartnerSePayAccount::PROVIDER_PAY2S,
            'account_number' => $number,
            'account_holder' => mb_strtoupper(trim((string) ($account['account_holder'] ?? $account['account_name'] ?? $account['holder_name'] ?? $existing?->account_holder ?? ''))) ?: 'CHUA RO',
        ];

        if ($bank) {
            $values += ['bank_code' => $bank['code'], 'bank_bin' => $bank['bin']];
        } elseif (! $existing) {
            $session->forceFill(['status' => 'failed', 'error_code' => 'BANK_UNKNOWN', 'error_message' => 'Không nhận diện được ngân hàng từ Pay2S — nhập tay ở CMS.'])->save();

            return;
        }

        $webhookToken = $this->createWebhook((string) ($account['user_bank_id'] ?? $account['id'] ?? ''));
        if ($webhookToken) {
            $values['webhook_token'] = $webhookToken;
        }

        $saved = PartnerSePayAccount::query()->updateOrCreate(['partner_id' => $session->partner_id, 'category_id' => $session->category_id], $values + ['is_active' => true]);
        $session->forceFill(['applied' => true])->save();

        // Vừa lưu tài khoản là đăng ký webhook ngay nếu bước trên chưa lấy được token.
        $this->ensureWebhook($saved);
    }

    /** Một số trường hợp phiên chưa kèm account → tra danh sách tài khoản của khách. */
    private function lookupAccount(PartnerPay2sLinkSession $session): ?array
    {
        $response = $this->http()->get($this->baseUrl() . '/v1/bankhub/customer-accounts', ['customer_reference' => $session->customer_reference]);
        $rows = collect($response->json('data') ?? [])->filter(fn ($r) => is_array($r));

        $row = $rows->first(fn ($r) => in_array(strtolower((string) ($r['status'] ?? $r['account_status'] ?? 'active')), ['active', 'connected'], true));

        return $row ? (array) ($row['account'] ?? $row) + $row : null;
    }

    /** Tạo webhook nhận tiền vào cho tài khoản vừa liên kết; trả token webhook (null nếu Pay2S không cấp → nhập tay như trước). */
    private function createWebhook(string $userBankId): ?string
    {
        if ($userBankId === '') {
            return null;
        }

        $response = $this->http()->post($this->baseUrl() . '/v1/webhooks', [
            'user_bank_id' => $userBankId,
            'webhook_url' => Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s'),
            'direction' => 'IN',
            'type' => 'IN',
        ]);

        if (! $response->successful()) {
            Log::warning('Pay2S BankHub tạo webhook thất bại', ['status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);

            return null;
        }

        $token = $response->json('data.webhook_token') ?? $response->json('data.token') ?? $response->json('data.secret') ?? $response->json('webhook_token') ?? $response->json('token');

        return filled($token) ? (string) $token : null;
    }

    /** @return array{code: string, bin: string}|null */
    private function matchBank(string $name): ?array
    {
        $needle = Str::lower(Str::ascii(trim($name)));
        // Pay2S dùng mã MBB (API Partner) / MBBank (Hosted Link); danh sách ngân hàng của hệ thống dùng mã MB.
        $needle = ['mbbank' => 'mb', 'mb bank' => 'mb', 'mbb' => 'mb'][$needle] ?? $needle;

        if ($needle === '') {
            return null;
        }

        foreach (config('banks.list', []) as $code => $bank) {
            if ($needle === Str::lower($code) || $needle === Str::lower(Str::ascii((string) $bank['short_name'])) || $needle === (string) $bank['bin']) {
                return ['code' => (string) $code, 'bin' => (string) $bank['bin']];
            }
        }

        return null;
    }

    /**
     * Kiểm tra tài khoản Pay2S của đối tác đã liên kết xong và nhận được tiền chưa (nút "Kiểm tra liên kết" ở CMS / API).
     * Mỗi mục: ok = true/false, hoặc null = không kiểm tra được (thiếu khoá). linked = tất cả mục kiểm tra được đều đạt.
     *
     * @return array{linked: bool, checks: array<int, array{key: string, label: string, ok: ?bool, message: string}>}
     */
    public function verify(PartnerSePayAccount $account): array
    {
        $checks = [];
        $add = function (string $key, string $label, ?bool $ok, string $message) use (&$checks) {
            $checks[] = compact('key', 'label', 'ok', 'message');
        };

        $add('provider', 'Cổng nhận tiền là Pay2S', $account->provider === PartnerSePayAccount::PROVIDER_PAY2S,
            $account->provider === PartnerSePayAccount::PROVIDER_PAY2S ? 'Đúng cổng Pay2S.' : 'Tài khoản đang chọn cổng ' . $account->providerLabel() . ' — đổi sang Pay2S nếu muốn kiểm tra.');

        $add('active', 'Đang bật làm kênh thu tiền', (bool) $account->is_active, $account->is_active ? 'Đang bật.' : 'Tài khoản đang tắt.');

        $add('webhook_token', 'Có token webhook Pay2S', filled($account->webhook_token),
            filled($account->webhook_token) ? 'Đã có token — webhook Pay2S gửi về sẽ được chấp nhận.' : 'Chưa có token: webhook của tài khoản này sẽ bị từ chối. Liên kết lại bằng nút "Liên kết ngân hàng" hoặc nhập token tay.');

        if ($this->configured()) {
            try {
                $linked = $this->findLinkedAccount($account);
                $add('pay2s_linked', 'Pay2S ghi nhận liên kết ngân hàng', $linked !== null,
                    $linked !== null ? 'Pay2S báo tài khoản ' . $account->account_number . ' đang liên kết.' : 'Pay2S chưa có liên kết đang hoạt động cho số tài khoản này (hoặc tài khoản được liên kết ở tài khoản Pay2S khác / chưa qua BankHub).');
            } catch (\Throwable $e) {
                $add('pay2s_linked', 'Pay2S ghi nhận liên kết ngân hàng', null, 'Không hỏi được Pay2S: ' . Str::limit($e->getMessage(), 150));
            }
        } else {
            $add('pay2s_linked', 'Pay2S ghi nhận liên kết ngân hàng', null, 'Chưa cấu hình khoá Partner API nên không hỏi được Pay2S.');
        }

        // Chỉ "đạt" khi giao dịch gần nhất THẬT SỰ xác nhận được đơn; giao dịch bị từ chối (sai số tiền, sai nội dung, đơn đã huỷ...) nêu rõ lý do.
        $last = \Modules\Payment\Entities\SePayTransaction::query()->where('provider', PartnerSePayAccount::PROVIDER_PAY2S)->where('partner_sepay_account_id', $account->id)->latest('id')->first();
        $add('last_transaction', 'Giao dịch gần nhất xác nhận được đơn', $last?->status === 'processed' ? true : null, match (true) {
            ! $last => 'Chưa có giao dịch nào về (bình thường nếu tài khoản mới liên kết — chuyển thử 1 khoản nhỏ đúng nội dung để chắc chắn).',
            $last->status === 'processed' => 'Giao dịch ' . $last->transaction_at?->format('d/m/Y H:i') . ' đã xác nhận đơn.',
            default => 'Giao dịch ' . $last->transaction_at?->format('d/m/Y H:i') . ' chưa xác nhận được đơn: ' . ($last->failure_reason ?: $last->status) . '.',
        });

        return ['linked' => collect($checks)->every(fn ($c) => $c['ok'] !== false), 'checks' => $checks];
    }

    private function findLinkedAccount(PartnerSePayAccount $account): ?array
    {
        $response = $this->http()->get($this->baseUrl() . '/v1/bankhub/customer-accounts', ['customer_reference' => 'partner-' . $account->partner_id]);

        if (! $response->successful()) {
            throw new RuntimeException('Pay2S trả lỗi ' . $response->status());
        }

        $row = collect($response->json('data') ?? [])->filter(fn ($r) => is_array($r))->first(function ($r) use ($account) {
            $number = (string) (Arr::get($r, 'account.account_number') ?? $r['account_number'] ?? '');
            $status = strtolower((string) ($r['account_status'] ?? $r['status'] ?? Arr::get($r, 'account.status') ?? 'active'));

            return $number === $account->account_number && in_array($status, ['active', 'connected', 'linked'], true);
        });

        return $row ?: null;
    }

    public function present(PartnerPay2sLinkSession $session): array
    {
        return [
            'session_id' => $session->session_id,
            'category_id' => $session->category_id,
            'status' => $session->status,
            'pending' => $session->isPending(),
            'connected' => $session->applied,
            'hosted_link_url' => $session->applied || in_array($session->status, PartnerPay2sLinkSession::FAILED, true) ? null : $session->hosted_link_url,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'error_code' => $session->error_code,
            'error_message' => $session->error_message,
        ];
    }
}
