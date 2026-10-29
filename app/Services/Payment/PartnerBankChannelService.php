<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Support\Banks;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\PartnerBankChannel;

/**
 * Lưu kênh chuyển khoản (VietQR + SePay) của đối tác — dùng chung cho API admin (PartnerBankChannelController) và lệnh sepay:channel.
 *
 * Tài khoản nhận mặc định lấy từ tab Tài chính của đối tác (partners.bank_*, nhập lúc đăng ký đối tác). Nhập tài khoản khác thì chủ tài
 * khoản PHẢI trùng chủ tài khoản ở tab Tài chính — cùng quy tắc với kênh PayOS (PartnerPayOsChannelService::verifyChannel()), để tiền
 * đặt phòng của khách không thể bị trỏ sang tài khoản của người khác.
 */
class PartnerBankChannelService
{
    /**
     * @param  array{bank_code?: string, account_number?: string, account_holder?: ?string, is_active?: bool, note?: ?string, use_financial_account?: bool}  $data
     * @param  bool  $enforceHolder  false chỉ dành cho lệnh CLI (--skip-holder-check), không bao giờ từ API
     */
    public function save(Partner $partner, array $data, ?string $byUserId = null, bool $enforceHolder = true): PartnerBankChannel
    {
        $channel = $partner->bankChannel ?? new PartnerBankChannel(['partner_id' => $partner->id, 'is_active' => true]);
        $useFinancial = (bool) ($data['use_financial_account'] ?? false)
            || (! $channel->exists && blank($data['bank_code'] ?? null) && blank($data['account_number'] ?? null));

        if ($useFinancial) {
            $financial = $this->financialAccount($partner);

            if (! $financial['bank_code'] || ! $financial['account_number']) {
                throw ValidationException::withMessages(['bank_code' => 'Tab Tài chính của đối tác chưa có ngân hàng/số tài khoản hợp lệ — cập nhật tab Tài chính hoặc nhập tài khoản nhận.']);
            }

            $data = ['bank_code' => $financial['bank_code'], 'account_number' => $financial['account_number'], 'account_holder' => $financial['account_holder']] + $data;
        }

        $channel->fill(collect($data)->only(['bank_code', 'account_number', 'is_active', 'note'])->all());

        $holder = filled($data['account_holder'] ?? null) ? $data['account_holder'] : ($channel->account_holder ?: $partner->bank_account_holder);
        $channel->account_holder = filled($holder) ? $this->normalizeName((string) $holder) : null;

        if ($enforceHolder) {
            $this->assertHolderMatches($partner, $channel);
        }

        if (! $channel->exists) {
            $this->assignNewKey($channel);
        }

        $changed = $channel->isDirty(['bank_code', 'account_number', 'account_holder', 'is_active']);
        $channel->updated_by = $byUserId;
        $channel->save();

        if ($changed) {
            $this->log($partner, $byUserId, 'Đổi kênh chuyển khoản nhận tiền đặt phòng (' . ($channel->bank()['short_name'] ?? $channel->bank_code) . ' …' . Str::substr($channel->account_number, -4)
                . ', chủ tài khoản: ' . ($channel->account_holder ?: 'không rõ') . '). ' . ($channel->is_active ? 'Đang dùng — tiền đặt phòng về thẳng đối tác.' : 'Đang tắt.'));
        }

        return $channel;
    }

    public function regenerateKey(Partner $partner, ?string $byUserId = null): PartnerBankChannel
    {
        $channel = $partner->bankChannel ?? throw ValidationException::withMessages(['channel' => 'Đối tác chưa cấu hình kênh chuyển khoản.']);

        $this->assignNewKey($channel);
        $channel->save();
        $this->log($partner, $byUserId, 'Cấp lại khoá webhook SePay cho kênh chuyển khoản.');

        return $channel;
    }

    /** @return array{bank_code: ?string, bank_name: ?string, account_number: ?string, account_holder: ?string} */
    public function financialAccount(Partner $partner): array
    {
        $bank = Banks::findByShortName($partner->bank_name);

        return [
            'bank_code'      => $bank['code'] ?? null,
            'bank_name'      => $partner->bank_name,
            'account_number' => filled($partner->bank_account_number) ? trim((string) $partner->bank_account_number) : null,
            'account_holder' => $partner->bank_account_holder,
        ];
    }

    /** Kênh đang nhận vào đúng tài khoản ở tab Tài chính chưa (cùng ngân hàng + số tài khoản). */
    public function matchesFinancial(Partner $partner, ?PartnerBankChannel $channel): bool
    {
        $financial = $this->financialAccount($partner);

        return $channel !== null && $financial['bank_code'] === $channel->bank_code && $financial['account_number'] === $channel->account_number;
    }

    /**
     * Mỗi chi nhánh của đối tác: tiền đặt phòng online đang về đâu — xem PayOsAccountResolver::destinationForCategory().
     *
     * @return list<array<string, mixed>>
     */
    public function branchDestinations(Partner $partner): array
    {
        return Category::query()->where('partner_id', $partner->id)->whereNull('parent_id')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Category $branch) => ['category_id' => $branch->id, 'name' => $branch->name] + PayOsAccountResolver::destinationForCategory($branch->id, $partner->id))
            ->values()->all();
    }

    public function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', trim(Str::upper(Str::ascii($name))));
    }

    private function assertHolderMatches(Partner $partner, PartnerBankChannel $channel): void
    {
        if (blank($partner->bank_account_holder)) {
            throw ValidationException::withMessages(['account_holder' => 'Đối tác chưa có tên chủ tài khoản ở tab Tài chính — cập nhật trước để đối chiếu với kênh chuyển khoản.']);
        }

        if (blank($channel->account_holder) || $channel->account_holder !== $this->normalizeName((string) $partner->bank_account_holder)) {
            throw ValidationException::withMessages(['account_holder' => 'Chủ tài khoản nhận (' . ($channel->account_holder ?: 'trống') . ') không trùng chủ tài khoản ở tab Tài chính (' . $partner->bank_account_holder . ').']);
        }
    }

    private function assignNewKey(PartnerBankChannel $channel): void
    {
        $key = Str::random(48);
        $channel->webhook_key = $key;
        $channel->webhook_key_hash = PartnerBankChannel::hashKey($key);
    }

    private function log(Partner $partner, ?string $byUserId, string $note): void
    {
        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $byUserId, 'note' => $note,
        ]);
    }
}
