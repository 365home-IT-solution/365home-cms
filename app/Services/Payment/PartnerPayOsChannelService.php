<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerPayOsAccount;
use PayOS\PayOS;

// Xem/lưu KÊNH PAYOS RIÊNG CỦA ĐỐI TÁC Homestay (API admin + trang quản trị dùng chung). Khi đổi khoá:
// gọi thử PayOS để chắc cả 3 khoá đúng, đối chiếu chủ tài khoản nhận tiền với tab Tài chính, ghi lịch sử hồ
// sơ (không kèm khoá) và đăng ký webhook. Khoá bí mật không bao giờ trả ra ngoài.
class PartnerPayOsChannelService
{
    // Dải mã riêng cho link gọi thử, không đụng đơn đặt phòng / gói dịch vụ (8...) / ký quỹ (9...).
    private const PROBE_ORDER_CODE_PREFIX = '7';

    private const PROBE_AMOUNT = 2000;

    public function data(Partner $partner): array
    {
        $account = $partner->payOsAccount;

        return [
            'partner_id'           => $partner->id,
            'payos_configured'     => (bool) $account?->isComplete(),
            'payos_client_id'      => $account?->client_id,
            'is_active'            => (bool) $account?->is_active,
            // Super Admin đã bật cho chủ đối tác tự nhập kênh chưa (tắt = chỉ 365home nhập hộ).
            'partner_setup_allowed' => (bool) $partner->payos_self_setup_enabled,
            // Tiền đặt phòng online của đối tác đang về đâu.
            'collected_by'         => $account?->isComplete() && $account->is_active && $partner->usesDirectPayment() ? 'partner' : 'platform',
            // Hợp đồng mẫu mới/phụ lục ký quỹ & thanh toán đã có hiệu lực chưa — chưa thì 365home vẫn thu hộ dù đã lưu kênh.
            'flow_effective_at'    => $partner->payment_flow_effective_at?->toIso8601String(),
            'account_holder'       => $account?->account_holder,
            'bank_account_holder'  => $partner->bank_account_holder,
            'note'                 => $account?->note,
            'webhook_url'          => route('webhook.payos'),
            'webhook_confirmed_at' => $account?->webhook_confirmed_at?->toIso8601String(),
            'updated_at'           => $account?->updated_at?->toIso8601String(),
            // Chi nhánh đang ghi đè bằng tài khoản PayOS riêng (quản lý ở trang quản trị web).
            'branch_overrides'     => BranchPayOsAccount::query()->with('category:id,name')
                ->whereIn('category_id', $partner->categories()->pluck('id'))->get()
                ->map(fn (BranchPayOsAccount $branch) => $this->branchData($partner, $branch))->values(),
        ];
    }

    /** Một kênh PayOS riêng của chi nhánh (không kèm khoá bí mật). is_effective = đang thực sự nhận tiền (bật và luồng tiền mới của đối tác đã hiệu lực). */
    public function branchData(Partner $partner, BranchPayOsAccount $branch): array
    {
        return [
            'category_id'          => $branch->category_id,
            'name'                 => $branch->category?->name,
            'payos_client_id'      => $branch->client_id,
            'is_active'            => (bool) $branch->is_active,
            'is_effective'         => $branch->isComplete() && $branch->is_active && $partner->usesDirectPayment(),
            'account_holder'       => $branch->account_holder,
            'note'                 => $branch->note,
            'webhook_confirmed_at' => $branch->webhook_confirmed_at?->toIso8601String(),
            'updated_at'           => $branch->updated_at?->toIso8601String(),
        ];
    }

    /** Chi nhánh có thuộc đối tác này không (chi nhánh do đối tác quản lý, kể cả khu vực con). */
    public function ownsBranch(Partner $partner, int $categoryId): bool
    {
        return $partner->categories()->whereKey($categoryId)->exists();
    }

    /**
     * Lưu kênh PayOS RIÊNG của một chi nhánh (ghi đè kênh của đối tác — dùng khi một đối tác có nhiều pháp nhân/tài khoản).
     * Cùng quy tắc với kênh đối tác: khoá gửi trống = giữ nguyên (tạo mới phải đủ 3 khoá), gọi thử PayOS, ghi lịch sử hồ sơ, đăng ký webhook,
     * báo Super Admin khi chủ đối tác tự đổi. Chủ tài khoản phải trùng tab Tài chính; riêng Super Admin được lưu tài khoản khác chủ
     * (pháp nhân khác của cùng đối tác) nhưng vẫn phải gọi thử PayOS thành công.
     *
     * @param  array{client_id?: ?string, api_key?: ?string, checksum_key?: ?string, is_active?: bool, note?: ?string}  $data
     */
    public function saveBranch(Partner $partner, Category $branch, array $data, User $by): BranchPayOsAccount
    {
        $account = BranchPayOsAccount::query()->where('category_id', $branch->id)->first() ?? new BranchPayOsAccount(['category_id' => $branch->id, 'is_active' => true]);

        $credentials = [
            'client_id'    => filled($data['client_id'] ?? null) ? trim((string) $data['client_id']) : $account->client_id,
            'api_key'      => filled($data['api_key'] ?? null) ? trim((string) $data['api_key']) : $account->api_key,
            'checksum_key' => filled($data['checksum_key'] ?? null) ? trim((string) $data['checksum_key']) : $account->checksum_key,
        ];

        foreach ($credentials as $field => $value) {
            if (blank($value)) {
                throw ValidationException::withMessages([$field => 'Vui lòng nhập đủ Client ID, API Key và Checksum Key của kênh PayOS.']);
            }
        }

        $changed = ! $account->exists || $credentials !== ['client_id' => $account->client_id, 'api_key' => $account->api_key, 'checksum_key' => $account->checksum_key];

        if ($changed) {
            $accountName = $this->verifyChannel($partner, $credentials, enforceHolder: ! $by->isSuperAdmin());
            $account->fill([...$credentials, 'account_holder' => $accountName, 'webhook_confirmed_at' => null]);
        }

        $wasActive = $account->exists ? (bool) $account->getOriginal('is_active') : null;
        $account->fill([
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $account->is_active,
            'note'      => array_key_exists('note', $data) ? $data['note'] : $account->note,
        ])->save();

        if ($changed) {
            $this->confirmWebhook($account);
        }

        if ($changed || $wasActive !== $account->is_active) {
            $this->logBranchChange($partner, $branch, $by, ($changed ? 'Đổi kênh PayOS riêng' : 'Kênh PayOS riêng') . ' của chi nhánh "' . $branch->name . '"'
                . ($changed ? ' (Client ID …' . Str::substr($account->client_id, -4) . ', chủ tài khoản: ' . ($account->account_holder ?: 'không rõ') . ').' : '.')
                . ' ' . ($account->is_active ? 'Đang dùng — tiền đặt phòng của chi nhánh về kênh này.' : 'Đang tắt — chi nhánh dùng kênh của đối tác.'));
        }

        return $account->fresh('category');
    }

    /** Xoá kênh riêng của chi nhánh: chi nhánh quay về dùng kênh của đối tác (hoặc 365home thu hộ nếu đối tác chưa có kênh). */
    public function removeBranch(Partner $partner, Category $branch, User $by): void
    {
        $account = BranchPayOsAccount::query()->where('category_id', $branch->id)->first();

        if (! $account) {
            return;
        }

        $account->delete();
        $this->logBranchChange($partner, $branch, $by, 'Xoá kênh PayOS riêng của chi nhánh "' . $branch->name . '" — chi nhánh dùng kênh của đối tác.');
    }

    private function logBranchChange(Partner $partner, Category $branch, User $by, string $note): void
    {
        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id, 'note' => $note,
        ]);

        // Đối tác tự đổi nơi nhận tiền: báo Super Admin để kiểm tra.
        if (! $by->isSuperAdmin()) {
            try {
                app(\App\Services\AdminNotificationService::class)->notify(
                    User::role(config('filament-shield.super_admin.name'))->get(),
                    'Đối tác tự đổi kênh PayOS của chi nhánh',
                    ($partner->legal_name ?: $partner->name) . ' (' . $by->fullname . '): ' . $note,
                    ['type' => 'partner_payment_channel_changed', 'partner_id' => $partner->id, 'partner_type' => $partner->partner_type, 'category_id' => $branch->id],
                    'heroicon-o-banknotes',
                    'warning',
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  array{client_id?: ?string, api_key?: ?string, checksum_key?: ?string, is_active?: bool, note?: ?string}  $data
     *                                                                                                                khoá gửi trống = giữ nguyên
     */
    public function save(Partner $partner, array $data, User $by): PartnerPayOsAccount
    {
        $account = $partner->payOsAccount ?? new PartnerPayOsAccount(['partner_id' => $partner->id, 'is_active' => true]);

        $credentials = [
            'client_id'    => filled($data['client_id'] ?? null) ? trim((string) $data['client_id']) : $account->client_id,
            'api_key'      => filled($data['api_key'] ?? null) ? trim((string) $data['api_key']) : $account->api_key,
            'checksum_key' => filled($data['checksum_key'] ?? null) ? trim((string) $data['checksum_key']) : $account->checksum_key,
        ];

        foreach ($credentials as $field => $value) {
            if (blank($value)) {
                throw ValidationException::withMessages([$field => 'Vui lòng nhập đủ Client ID, API Key và Checksum Key của kênh PayOS.']);
            }
        }

        $changed = ! $account->exists || $credentials !== ['client_id' => $account->client_id, 'api_key' => $account->api_key, 'checksum_key' => $account->checksum_key];

        if ($changed) {
            $accountName = $this->verifyChannel($partner, $credentials);
            $account->fill([...$credentials, 'account_holder' => $accountName, 'webhook_confirmed_at' => null]);
        }

        $wasActive = $account->exists ? (bool) $account->getOriginal('is_active') : null;
        $account->fill([
            'is_active'  => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $account->is_active,
            'note'       => array_key_exists('note', $data) ? $data['note'] : $account->note,
            'updated_by' => $by->id,
        ])->save();

        if ($changed) {
            $this->confirmWebhook($account);
        }

        if ($changed || $wasActive !== $account->is_active) {
            PartnerStatusLog::create([
                'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id,
                'note' => ($changed ? 'Đổi kênh PayOS nhận tiền đặt phòng (Client ID …' . Str::substr($account->client_id, -4) . ', chủ tài khoản: ' . ($account->account_holder ?: 'không rõ') . ').' : 'Kênh PayOS của đối tác:')
                    . ' ' . ($account->is_active ? 'Đang dùng — tiền đặt phòng về thẳng đối tác.' : 'Đang tắt — 365home thu hộ.'),
            ]);
        }

        // Đối tác tự đổi nơi nhận tiền: báo Super Admin để kiểm tra (đổi do tài khoản bị chiếm thì phát hiện được sớm).
        if (($changed || $wasActive !== $account->is_active) && ! $by->isSuperAdmin()) {
            try {
                app(\App\Services\AdminNotificationService::class)->notify(
                    User::role(config('filament-shield.super_admin.name'))->get(),
                    'Đối tác tự đổi kênh PayOS nhận tiền',
                    ($partner->legal_name ?: $partner->name) . ' (' . $by->fullname . ') vừa ' . ($changed ? 'đổi kênh PayOS — chủ tài khoản: ' . ($account->account_holder ?: 'không rõ') : ($account->is_active ? 'bật' : 'tắt') . ' kênh PayOS riêng') . '. Kiểm tra nếu đây không phải thay đổi đã báo trước.',
                    ['type' => 'partner_payment_channel_changed', 'partner_id' => $partner->id, 'partner_type' => $partner->partner_type],
                    'heroicon-o-banknotes',
                    'warning',
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $account->fresh();
    }

    /** Super Admin bật/tắt quyền tự nhập kênh PayOS cho chủ đối tác này. Tắt không đụng tới kênh đang lưu. */
    public function setPartnerSetupAllowed(Partner $partner, bool $allowed, User $by): void
    {
        if ((bool) $partner->payos_self_setup_enabled === $allowed) {
            return;
        }

        $partner->forceFill(['payos_self_setup_enabled' => $allowed])->saveQuietly();

        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id,
            'note' => $allowed ? 'Cho phép chủ đối tác tự nhập kênh PayOS.' : 'Thu lại quyền tự nhập kênh PayOS của chủ đối tác (chỉ 365home nhập).',
        ]);
    }

    /** Chủ đối tác (không phải nhân viên) của chính đối tác này, và đã được Super Admin bật quyền tự nhập. */
    public function partnerOwnerCanManage(Partner $partner, User $user): bool
    {
        return (bool) $partner->payos_self_setup_enabled && $user->partner_id === $partner->id
            && ($user->hasRole('partner') || $user->can('update_partner'));
    }

    /** Đăng ký URL webhook của 365home cho kênh PayOS — cần URL công khai nên có thể thất bại trên máy local. */
    public function confirmWebhook(PartnerPayOsAccount|BranchPayOsAccount $account): bool
    {
        try {
            $this->client(...$account->credentials())->confirmWebhook(route('webhook.payos'));
            $account->update(['webhook_confirmed_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Kênh PayOS: chưa đăng ký được webhook', ['partner' => $account->partner_id ?? null, 'category' => $account->category_id ?? null, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Gọi thử PayOS và đối chiếu chủ tài khoản. Sai khoá hoặc lệch chủ tài khoản → 422, không lưu.
     *
     * @param  array{client_id: string, api_key: string, checksum_key: string}  $credentials
     * @return string|null tên chủ tài khoản nhận tiền của kênh (PayOS trả về)
     */
    private function verifyChannel(Partner $partner, array $credentials, bool $enforceHolder = true): ?string
    {
        if ($enforceHolder && blank($partner->bank_account_holder)) {
            throw ValidationException::withMessages(['client_id' => 'Đối tác chưa có tên chủ tài khoản ở tab Tài chính — cập nhật trước để đối chiếu với kênh PayOS.']);
        }

        try {
            $accountName = $this->probe($credentials['client_id'], $credentials['api_key'], $credentials['checksum_key']);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['client_id' => 'PayOS không chấp nhận kênh này (' . $e->getMessage() . ') — kiểm tra lại Client ID, API Key và Checksum Key.']);
        }

        if ($enforceHolder && filled($accountName) && $this->normalizeName($accountName) !== $this->normalizeName((string) $partner->bank_account_holder)) {
            throw ValidationException::withMessages(['client_id' => 'Chủ tài khoản của kênh PayOS (' . $accountName . ') không trùng chủ tài khoản ở tab Tài chính (' . $partner->bank_account_holder . ').']);
        }

        return $accountName;
    }

    /**
     * Tạo rồi huỷ ngay 1 link 2.000đ: PayOS chỉ trả link khi Client ID/API Key đúng, SDK chỉ nhận phản hồi khi chữ ký
     * khớp Checksum Key — tức cả 3 khoá đều đúng; phản hồi kèm tên chủ tài khoản nhận tiền.
     */
    protected function probe(string $clientId, string $apiKey, string $checksumKey): ?string
    {
        $payOS = $this->client($clientId, $apiKey, $checksumKey);
        $orderCode = (int) (self::PROBE_ORDER_CODE_PREFIX . substr((string) (int) (microtime(true) * 1000), -13));
        $home = rtrim((string) config('app.url'), '/') . '/';

        $link = $payOS->createPaymentLink([
            'orderCode'   => $orderCode,
            'amount'      => self::PROBE_AMOUNT,
            'description' => 'Kiem tra kenh',
            'returnUrl'   => $home,
            'cancelUrl'   => $home,
            'items'       => [['name' => 'Kiem tra kenh PayOS', 'quantity' => 1, 'price' => self::PROBE_AMOUNT]],
            'expiredAt'   => now()->addMinutes(5)->timestamp,
        ]);

        try {
            $payOS->cancelPaymentLink($orderCode, 'Kiem tra kenh');
        } catch (\Throwable $e) {
            Log::warning('Kênh PayOS đối tác: không huỷ được link gọi thử (tự hết hạn sau 5 phút)', ['error' => $e->getMessage()]);
        }

        return $link['accountName'] ?? null;
    }

    protected function client(string $clientId, string $apiKey, string $checksumKey): PayOS
    {
        return new PayOS($clientId, $apiKey, $checksumKey);
    }

    private function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', trim(Str::upper(Str::ascii($name))));
    }
}
