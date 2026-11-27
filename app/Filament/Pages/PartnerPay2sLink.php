<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\User;
use App\Services\Payment\Pay2SBankHubService;
use App\Support\Banks;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerPay2sLinkSession;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;

// Chủ đối tác Homestay TỰ cài tài khoản nhận tiền ngay trong CMS — không cần 365home hay Super Admin làm hộ. Một đối tác có nhiều chi nhánh: MỖI chi nhánh (hoặc "cả đối tác" làm mặc định)
// chọn MỘT trong hai cách nhận tiền:
//  - TỰ ĐỘNG (Pay2S): liên kết ngân hàng với Pay2S → đơn được xác nhận tự động. Ngân hàng OpenAPI (ACB, MB Bank, BIDV): nhập thông tin rồi nhập OTP ngay trong trang; ngân hàng khác: cửa sổ Hosted Link
//    của Pay2S (thông tin và OTP nhập trực tiếp trên khung của Pay2S, 365home không nhận).
//  - THỦ CÔNG: chỉ cần thông tin ngân hàng cơ bản (ngân hàng, số tài khoản, chủ tài khoản); khách chuyển khoản theo QR, đối tác tự đối chiếu và bấm xác nhận ở "Chờ xác nhận chuyển khoản".
// Đối tác tự đổi nơi nhận tiền thì Super Admin được thông báo. Dùng chung Pay2SBankHubService với API admin.
class PartnerPay2sLink extends Page
{
    protected static string $view = 'filament.pages.partner-pay2s-link';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Liên kết ngân hàng Pay2S';

    protected static ?string $title = 'Liên kết ngân hàng Pay2S';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'partner-pay2s-link';

    /** Phạm vi: null / "" = tài khoản mặc định của cả đối tác; có giá trị = tài khoản riêng của chi nhánh đó (không khai kiểu: ô chọn gửi chuỗi rỗng). */
    public $categoryId = '';

    /** Cách nhận tiền ĐANG CHỌN ở phạm vi này: auto (Pay2S, có OTP) | manual (thủ công). */
    public string $mode = 'auto';

    public ?string $sessionId = null;

    /** @var array<int, array{key: string, label: string, ok: ?bool, message: string}>|null */
    public ?array $checks = null;

    /** Liên kết nhanh bằng OTP (ngân hàng OpenAPI). @var array<string, string> */
    public array $bank = ['bank' => 'MB', 'account_type' => 'personal', 'account_number' => '', 'account_holder' => '', 'phone' => '', 'email' => '', 'cccd' => '', 'merchant_id' => '', 'username' => ''];

    /** Tab đang chọn khi liên kết tự động: otp (nhập thông tin + OTP ngay trên trang) | window (cửa sổ Pay2S). */
    public string $linkTab = 'otp';

    public bool $awaitingOtp = false;

    public string $otp = '';

    /** Nhập tài khoản thủ công. @var array<string, string> */
    public array $manual = ['bank_code' => '', 'account_number' => '', 'account_holder' => ''];

    /** Dán token webhook Pay2S (khi tài khoản tự động chưa có token). */
    public string $webhookToken = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->partner_id === null || $user->isSuperAdmin()) {
            return false;
        }

        $partner = Partner::withoutGlobalScopes()->find($user->partner_id);

        return $partner !== null && ! $partner->isSystemPartner() && ! $partner->isMinihouse()
            && ($user->hasRole('partner') || $user->can('page_PartnerPay2sLink'));
    }

    /** SDK Hosted Link của Pay2S (bản 1.1.0): môi trường UAT dùng máy chủ SDK riêng. */
    public function sdkUrl(): string
    {
        $uat = str_contains((string) \App\Support\Pay2SConfig::get('partner_api_url', ''), 'uat');

        return ($uat ? 'https://merchants-uat.pay2s.vn' : 'https://merchant.pay2s.vn') . '/sdk/pay2s-bankhub-1.1.0.js';
    }

    public function partner(): ?Partner
    {
        $id = auth()->user()?->partner_id;

        return $id ? Partner::withoutGlobalScopes()->find($id) : null;
    }

    /** @return array<int, string> id chi nhánh => tên, các chi nhánh của chính đối tác */
    public function branches(): array
    {
        $partner = $this->partner();

        return $partner ? $partner->categories()->withoutGlobalScopes()->orderBy('name')->pluck('name', 'id')->all() : [];
    }

    /** @return array<string, array{label: string, pay2s: string, types: list<string>, cccd: bool, merchant: bool, username_business: bool}> */
    public function openApiBanks(): array
    {
        return Pay2SBankHubService::OPENAPI_BANKS;
    }

    /** @return array<string, string> mã => tên ngân hàng cho ô nhập thủ công */
    public function bankOptions(): array
    {
        return collect(Banks::all())->mapWithKeys(fn (array $b, string $code) => [$code => $b['short_name']])->all();
    }

    /** Phạm vi đang chọn: id chi nhánh hoặc null (cả đối tác). */
    private function scope(): ?int
    {
        return filled($this->categoryId) && is_numeric($this->categoryId) ? (int) $this->categoryId : null;
    }

    public function account(): ?PartnerSePayAccount
    {
        $partner = $this->partner();

        return $partner ? PartnerSePayAccount::query()->where('partner_id', $partner->id)->where('category_id', $this->scope())->first() : null;
    }

    /** Lý do không thao tác được (kênh PayOS riêng đang bật...). Liên kết bằng API/Hosted Link còn cần khoá Partner API của Pay2S. */
    public function blockedReason(bool $needsPartnerApi = true): ?string
    {
        $partner = $this->partner();

        if (! $partner) {
            return 'Không tìm thấy đối tác.';
        }

        if ($needsPartnerApi) {
            return app(Pay2SBankHubService::class)->startBlockedReason($partner) ?? $this->payosBranchReason();
        }

        return $this->payosChannelReason($partner);
    }

    private function payosChannelReason(Partner $partner): ?string
    {
        if (PartnerPayOsAccount::query()->where('partner_id', $partner->id)->where('is_active', true)->exists()) {
            return 'Đối tác đang dùng kênh PayOS riêng. Tắt kênh PayOS trước khi dùng Pay2S (mỗi đối tác chỉ dùng một kênh).';
        }

        return $this->payosBranchReason();
    }

    private function payosBranchReason(): ?string
    {
        if ($this->scope() && BranchPayOsAccount::query()->where('category_id', $this->scope())->where('is_active', true)->exists()) {
            return 'Chi nhánh này đang dùng kênh PayOS riêng. Tắt kênh PayOS của chi nhánh trước.';
        }

        return null;
    }

    public function session(): ?PartnerPay2sLinkSession
    {
        $partner = $this->partner();

        return $partner && $this->sessionId
            ? PartnerPay2sLinkSession::query()->where('partner_id', $partner->id)->where('session_id', $this->sessionId)->first()
            : null;
    }

    public function mount(): void
    {
        $this->syncMode();
    }

    /** Đặt cách nhận tiền theo tài khoản đang có ở phạm vi (chưa có tài khoản = tự động) và nạp sẵn form thủ công. */
    private function syncMode(): void
    {
        $account = $this->account();

        $this->mode = $account?->isManual() ? 'manual' : 'auto';
        $this->manual = $account?->isManual()
            ? ['bank_code' => (string) $account->bank_code, 'account_number' => (string) $account->account_number, 'account_holder' => (string) $account->account_holder]
            : ['bank_code' => '', 'account_number' => '', 'account_holder' => ''];
        $this->webhookToken = '';
    }

    public function updatedCategoryId(): void
    {
        $this->sessionId = null;
        $this->checks = null;
        $this->resetOtpStep();
        $this->syncMode();
    }

    /** Chọn phạm vi từ bảng tổng quan: '' = cả đối tác, số = chi nhánh. */
    public function selectScope(string $categoryId = ''): void
    {
        $this->categoryId = $categoryId;
        $this->updatedCategoryId();
    }

    public function setLinkTab(string $tab): void
    {
        if (in_array($tab, ['otp', 'window'], true)) {
            $this->linkTab = $tab;
        }
    }

    /** Đổi cách nhận tiền đang chọn (chỉ đổi giao diện; áp dụng khi liên kết / lưu). */
    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['auto', 'manual'], true)) {
            return;
        }

        $this->mode = $mode;
        $this->resetOtpStep();
        $this->checks = null;
    }

    /**
     * Tổng quan mọi phạm vi của đối tác: "cả đối tác" + từng chi nhánh — cách nhận tiền, ngân hàng, số tài khoản. Chi nhánh chưa có tài khoản riêng dùng tài khoản mặc định của đối tác.
     *
     * @return list<array{id: string, name: string, mode: string, bank: ?string, number: ?string, own: bool}>
     */
    public function overview(): array
    {
        $partner = $this->partner();

        if (! $partner) {
            return [];
        }

        $accounts = PartnerSePayAccount::query()->where('partner_id', $partner->id)->get()->keyBy(fn (PartnerSePayAccount $a) => (string) ($a->category_id ?? ''));
        $default = $accounts->get('');
        $rows = [];

        foreach (['' => 'Cả đối tác (mặc định)'] + array_map(fn ($name) => 'Chi nhánh: ' . $name, $this->branches()) as $id => $name) {
            $id = (string) $id;
            $own = $accounts->get($id);
            $used = $own ?? $default;

            $rows[] = [
                'id' => $id, 'name' => $name, 'own' => (bool) $own,
                'mode' => $used === null ? 'none' : ($used->isManual() ? 'manual' : 'auto'),
                'bank' => $used?->bank_code, 'number' => $used?->account_number,
            ];
        }

        return $rows;
    }

    /** Đổi ngân hàng / loại tài khoản: bỏ các ô không còn áp dụng. */
    public function updatedBank(): void
    {
        $cfg = $this->openApiBanks()[$this->bank['bank']] ?? null;

        if ($cfg && ! in_array($this->bank['account_type'], $cfg['types'], true)) {
            $this->bank['account_type'] = 'personal';
        }

        $this->resetOtpStep();
    }

    private function resetOtpStep(): void
    {
        $this->awaitingOtp = false;
        $this->otp = '';
    }

    private function guard(bool $needsPartnerApi = true): ?Partner
    {
        $partner = $this->partner();

        if (! $partner || ! static::canAccess()) {
            Notification::make()->title('Không có quyền thực hiện')->danger()->send();

            return null;
        }

        if ($this->scope() !== null && ! array_key_exists($this->scope(), $this->branches())) {
            Notification::make()->title('Chi nhánh không thuộc đối tác này')->danger()->send();

            return null;
        }

        if ($reason = $this->blockedReason($needsPartnerApi)) {
            Notification::make()->title($reason)->danger()->persistent()->send();

            return null;
        }

        return $partner;
    }

    // ───────────────────────── 1. Liên kết nhanh bằng OTP ─────────────────────────

    public function submitBank(): void
    {
        $partner = $this->guard();

        if (! $partner) {
            return;
        }

        $cfg = $this->openApiBanks()[$this->bank['bank']] ?? null;

        if (! $cfg) {
            Notification::make()->title('Ngân hàng này chưa hỗ trợ liên kết nhanh bằng OTP — dùng "Liên kết qua Pay2S".')->danger()->send();

            return;
        }

        $this->validate(Pay2SBankHubService::openApiRules($this->bank, 'bank.'), [], [
            'bank.account_number' => 'số tài khoản', 'bank.account_holder' => 'tên chủ tài khoản', 'bank.phone' => 'số điện thoại', 'bank.email' => 'email',
            'bank.cccd' => 'CCCD/MST', 'bank.username' => 'tên đăng nhập ACB OneBiz',
        ]);

        $this->applyResult(app(Pay2SBankHubService::class)->submitOpenApiLink($partner, $this->scope(), $this->bank));
    }

    public function confirmOtp(): void
    {
        $partner = $this->guard();

        if (! $partner || ! $this->awaitingOtp) {
            return;
        }

        $this->validate(['otp' => ['required', 'regex:/^[0-9]{4,10}$/']], [], ['otp' => 'mã OTP']);

        $otp = $this->otp;
        $this->otp = ''; // không giữ OTP trong trạng thái trang sau khi gửi

        $this->applyResult(app(Pay2SBankHubService::class)->confirmOpenApiLink($partner, $this->scope(), $this->bank, $otp));
    }

    public function cancelOtp(): void
    {
        $this->resetOtpStep();
    }

    /** Hiển thị kết quả của service dùng chung (cũng là service mà API dùng). @param array{state: string, message: string, account: ?PartnerSePayAccount} $result */
    private function applyResult(array $result): void
    {
        switch ($result['state']) {
            case 'otp_required':
                $this->awaitingOtp = true;
                $this->otp = '';
                Notification::make()->title('Ngân hàng đã gửi mã OTP')->body('Nhập mã OTP gửi về số điện thoại đăng ký với ngân hàng vào ô bên dưới.')->success()->send();

                return;

            case 'hosted_link':
                // Pay2S chưa bật API nhập OTP trực tiếp: chuyển sang cửa sổ liên kết của Pay2S để đối tác vẫn làm được ngay.
                Notification::make()->title('Pay2S chưa bật nhập OTP trực tiếp cho ứng dụng — mở cửa sổ liên kết của Pay2S')->body('Nhập thông tin ngân hàng và OTP trong cửa sổ Pay2S vừa hiện ra.')->warning()->send();
                $this->startLink();

                return;

            case 'connected':
                $this->resetOtpStep();
                $this->mode = 'auto';
                $this->bank = ['bank' => $this->bank['bank'], 'account_type' => 'personal', 'account_number' => '', 'account_holder' => '', 'phone' => '', 'email' => '', 'cccd' => '', 'merchant_id' => '', 'username' => ''];
                $this->checks = null;
                Notification::make()->title('Đã liên kết ngân hàng thành công')->body($result['message'])->success()->send();

                return;

            default: // otp_invalid | error — giữ nguyên bước hiện tại để đối tác nhập lại
                Notification::make()->title($result['message'])->danger()->persistent()->send();
        }
    }

    // ───────────────────────── 2. Liên kết qua Pay2S (Hosted Link) ─────────────────────────

    public function startLink(): void
    {
        $partner = $this->guard();

        if (! $partner) {
            return;
        }

        try {
            $session = app(Pay2SBankHubService::class)->createSession($partner, (string) auth()->id(), $this->scope());
        } catch (\Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->sessionId = $session->session_id;
        $this->checks = null;
        $this->linkTab = 'window'; // trạng thái phiên hiện ở tab cửa sổ Pay2S

        // Trình duyệt mở SDK Pay2S dạng cửa sổ nổi (modal) ngay trên trang này: đối tác nhập thông tin ngân hàng và OTP tại đó, không rời trang.
        $this->dispatch('pay2s-open', url: (string) $session->hosted_link_url, sessionId: $session->session_id);

        Notification::make()->title('Đã tạo phiên liên kết')->body('Nhập thông tin ngân hàng và OTP trong cửa sổ Pay2S vừa hiện ra.')->success()->send();
    }

    /** Hỏi Pay2S kết quả phiên (trang tự gọi mỗi vài giây khi đang chờ). Gọi lặp lại an toàn. */
    public function refreshSession(): void
    {
        $session = $this->session();

        if (! $session) {
            return;
        }

        $wasApplied = $session->applied;

        try {
            $session = app(Pay2SBankHubService::class)->refresh($session);
        } catch (\Throwable $e) {
            Notification::make()->title('Không hỏi được Pay2S: ' . $e->getMessage())->warning()->send();

            return;
        }

        if ($session->applied && ! $wasApplied) {
            if ($partner = $this->partner()) {
                $this->logChange($partner, 'Tự liên kết ngân hàng Pay2S (Hosted Link).');
            }

            Notification::make()->title('Đã liên kết ngân hàng thành công')->body('Tài khoản đã được lưu và webhook nhận tiền đã được tạo.')->success()->send();
        }
    }

    // ───────────────────────── 3. Thủ công (chỉ thông tin ngân hàng cơ bản) ─────────────────────────

    public function saveManual(): void
    {
        $partner = $this->guard(false);

        if (! $partner) {
            return;
        }

        $existing = $this->account();

        $data = $this->validate([
            'manual.bank_code' => ['required', 'in:' . implode(',', Banks::codes())],
            'manual.account_number' => ['required', 'regex:/^[0-9A-Za-z.-]{6,50}$/'],
            'manual.account_holder' => ['required', 'string', 'max:255'],
        ], [], ['manual.bank_code' => 'ngân hàng', 'manual.account_number' => 'số tài khoản', 'manual.account_holder' => 'tên chủ tài khoản'])['manual'];

        $number = trim($data['account_number']);

        if (app(Pay2SBankHubService::class)->numberTaken((string) $partner->id, $this->scope(), $number)) {
            $this->addError('manual.account_number', 'Số tài khoản này đã gắn cho một đối tác hoặc chi nhánh khác.');

            return;
        }

        PartnerSePayAccount::query()->updateOrCreate(['partner_id' => $partner->id, 'category_id' => $this->scope()], [
            'provider' => PartnerSePayAccount::PROVIDER_MANUAL,
            'bank_code' => $data['bank_code'],
            'bank_bin' => Banks::all()[$data['bank_code']]['bin'],
            'account_number' => $number,
            'account_holder' => mb_strtoupper(trim($data['account_holder'])),
            'webhook_token' => null,
            'is_active' => true,
            'updated_by' => (string) auth()->id(),
        ]);

        $this->logChange($partner, 'Chọn nhận tiền THỦ CÔNG — ' . $data['bank_code'] . ' ' . $number . ($existing ? ' (thay ' . $existing->providerLabel() . ' ' . $existing->account_number . ')' : ''));
        $this->checks = null;

        Notification::make()->title('Đã lưu tài khoản nhận tiền thủ công')->body('Khách chuyển khoản theo QR; bạn đối chiếu và bấm xác nhận ở mục "Chờ xác nhận chuyển khoản".')->success()->send();
    }

    /** Tài khoản TỰ ĐỘNG đã có nhưng Pay2S chưa cấp token webhook: đối tác dán token Pay2S sinh cho webhook của tài khoản này. */
    public function saveWebhookToken(): void
    {
        $partner = $this->guard(false);
        $account = $this->account();

        if (! $partner || ! $account || $account->isManual()) {
            return;
        }

        $this->validate(['webhookToken' => ['required', 'string', 'max:500']], [], ['webhookToken' => 'token webhook']);

        $account->update(['webhook_token' => trim($this->webhookToken), 'updated_by' => (string) auth()->id()]);
        $this->webhookToken = '';

        Notification::make()->title('Đã lưu token webhook')->body('Bấm "Kiểm tra liên kết" để xác nhận.')->success()->send();
    }

    /** Lấy token webhook TỰ ĐỘNG: hệ thống tạo webhook "Nhận tiền" ở Pay2S cho tài khoản đã liên kết rồi lưu token (đối tác không phải chép tay). */
    public function provisionWebhook(): void
    {
        $partner = $this->guard();
        $account = $this->account();

        if (! $partner || ! $account || $account->isManual()) {
            return;
        }

        $result = app(Pay2SBankHubService::class)->provisionWebhook($account);

        if ($result['ok'] && $result['created']) {
            $this->logChange($partner, 'Tạo webhook nhận tiền Pay2S tự động — ' . $account->account_number);
        }

        Notification::make()->title($result['message'])->{$result['ok'] ? 'success' : 'danger'}()->persistent(! $result['ok'])->send();
    }

    // ───────────────────────── Chung ─────────────────────────

    /** "Kiểm tra liên kết": đã liên kết / đủ token webhook / đã nhận giao dịch chưa. */
    public function check(): void
    {
        $account = $this->account();

        if (! $account) {
            $this->checks = null;
            Notification::make()->title('Chưa có tài khoản nhận tiền ở phạm vi này.')->warning()->send();

            return;
        }

        $this->checks = app(Pay2SBankHubService::class)->verify($account)['checks'];
    }

    private function logChange(Partner $partner, string $note): void
    {
        app(Pay2SBankHubService::class)->recordChange($partner, auth()->user(), $note);
    }
}
