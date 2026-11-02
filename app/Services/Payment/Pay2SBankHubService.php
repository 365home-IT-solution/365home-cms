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

    /** Tạo phiên Hosted Link (15 phút). @throws RuntimeException khi Pay2S lỗi / chưa cấu hình. */
    public function createSession(Partner $partner, ?string $userId = null): PartnerPay2sLinkSession
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

        $existing = PartnerSePayAccount::query()->where('partner_id', $session->partner_id)->first();
        $taken = PartnerSePayAccount::query()->where('account_number', $number)->where('partner_id', '!=', $session->partner_id)->exists();

        if ($taken) {
            $session->forceFill(['status' => 'failed', 'error_code' => 'ACCOUNT_TAKEN', 'error_message' => 'Số tài khoản này đã gắn cho một đối tác khác.'])->save();

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

        PartnerSePayAccount::query()->updateOrCreate(['partner_id' => $session->partner_id], $values + ['is_active' => true]);
        $session->forceFill(['applied' => true])->save();
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

        $last = \Modules\Payment\Entities\SePayTransaction::query()->where('provider', PartnerSePayAccount::PROVIDER_PAY2S)->where('partner_sepay_account_id', $account->id)->latest('transaction_at')->first();
        $add('last_transaction', 'Đã nhận giao dịch từ tài khoản này', $last ? true : null,
            $last ? 'Giao dịch gần nhất: ' . $last->transaction_at?->format('d/m/Y H:i') . '.' : 'Chưa có giao dịch nào về (bình thường nếu tài khoản mới liên kết — chuyển thử 1 khoản nhỏ để chắc chắn).');

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
