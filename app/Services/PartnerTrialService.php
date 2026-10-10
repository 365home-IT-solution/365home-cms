<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\PartnerStatusLog;
use App\Models\User;
use App\Settings\EscrowSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MIỄN PHÍ THÁNG ĐẦU cho đối tác Homestay MỚI ở ngoài các tỉnh bắt buộc ký quỹ ngay (mặc định chỉ Thành phố Cần Thơ).
 *
 * Trong thời gian miễn phí (kể từ lúc hợp đồng có hiệu lực, mặc định 1 tháng): đối tác ký hợp đồng bình thường, đăng ký và bán phòng; KHÔNG hoa hồng, KHÔNG
 * bắt nạp ký quỹ, KHÔNG đối soát hoa hồng (đơn do 365home thu hộ vẫn được chi lại 100%); khoản giảm do 365home phát hành trừ vào doanh thu của đối tác,
 * 365home không bù. Hết thời gian: ký quỹ và hoa hồng chạy như đối tác mới ban đầu (chưa nạp đủ thì ngưng bán). Trước hạn 7 ngày (cấu hình) hệ thống nhắc nạp.
 *
 * Đây là chính sách có công tắc (App\Settings\EscrowSettings::$free_trial_enabled, mặc định TẮT). Căn cứ pháp lý là NỘI DUNG HỢP ĐỒNG: chỉ hợp đồng được tạo lúc công
 * tắc bật và đối tác đủ điều kiện mới có điều "Ưu đãi tháng đầu" (CLAUSE_MARKER); khi ký số hệ thống chỉ cấp miễn phí cho hợp đồng có điều đó — bật/tắt công tắc
 * sau này không làm đổi hợp đồng đã tạo. Đối tác đang hoạt động (chuyển sang bằng phụ lục) và đối tác Cần Thơ không bao giờ được miễn.
 */
class PartnerTrialService
{
    /** Đoạn nhận diện điều khoản miễn phí trong nội dung hợp đồng (xem PartnerContractRenderer). */
    public const CLAUSE_MARKER = 'Ưu đãi tháng đầu';

    public function __construct(private EscrowService $escrow, private PartnerNotifier $notifier) {}

    // ───────────────────────── Cấu hình ─────────────────────────

    private function settings(): ?EscrowSettings
    {
        try {
            return app(EscrowSettings::class);
        } catch (\Throwable $e) {
            report($e);

            return null; // chưa chạy migration cấu hình = coi như công tắc tắt
        }
    }

    public function enabled(): bool
    {
        return (bool) $this->settings()?->free_trial_enabled;
    }

    public function months(): int
    {
        return max(1, (int) ($this->settings()?->free_trial_months ?? 1));
    }

    /** @return int[] */
    public function immediateProvinceCodes(): array
    {
        return array_map('intval', (array) ($this->settings()?->immediate_province_codes ?? [92]));
    }

    /** @return int[] số ngày trước hạn cần nhắc, giảm dần */
    public function reminderDays(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($this->settings()?->reminder_days ?? [7, 1])), fn (int $d) => $d > 0)));
        rsort($days);

        return $days;
    }

    // ───────────────────────── Điều kiện ─────────────────────────

    /** Lý do KHÔNG được miễn phí (null = đủ điều kiện về địa bàn và chống lạm dụng). Không xét công tắc và việc có phải hợp đồng đầu tiên hay không. */
    public function ineligibleReason(Partner $partner): ?string
    {
        if ($partner->isMinihouse() || $partner->isPlatformPartner() || $partner->isSystemPartner()) {
            return 'Không áp dụng cho MiniHouse và đối tác hệ thống.';
        }
        if ($partner->province_code === null) {
            return 'Hồ sơ chưa có tỉnh/thành phố của cơ sở kinh doanh.';
        }

        $immediate = $this->immediateProvinceCodes();

        if (in_array((int) $partner->province_code, $immediate, true)) {
            return 'Cơ sở kinh doanh thuộc tỉnh/thành phải ký quỹ ngay.';
        }
        if ($this->hasBranchIn($partner, $immediate)) {
            return 'Có chi nhánh thuộc tỉnh/thành phải ký quỹ ngay.';
        }

        return $this->alreadyUsed($partner) ? 'Mã số thuế, CCCD người đại diện hoặc số điện thoại này đã từng được miễn phí tháng đầu.' : null;
    }

    /** Hợp đồng lần đầu của đối tác này có được ghi điều "Ưu đãi tháng đầu" không (dùng khi dựng nội dung hợp đồng). */
    public function offersOnFirstContract(Partner $partner): bool
    {
        return $this->enabled()
            && ! $partner->usesDirectPayment()
            && blank($partner->contract_signed_at)
            && $this->ineligibleReason($partner) === null;
    }

    /** Hợp đồng này có điều miễn phí không (căn cứ để cấp khi ký số). */
    public function contractPromisesTrial(PartnerContractVersion $version): bool
    {
        return ! $version->isAddendum() && str_contains((string) $version->content, self::CLAUSE_MARKER);
    }

    /** @param  int[]  $codes */
    private function hasBranchIn(Partner $partner, array $codes): bool
    {
        if ($codes === []) {
            return false;
        }

        $categoryIds = $partner->categories()->pluck('id');

        return $categoryIds->isNotEmpty() && DB::table('province_branches')
            ->join('provinces', 'provinces.id', '=', 'province_branches.province_id')
            ->whereIn('province_branches.categorie_id', $categoryIds)
            ->whereIn('provinces.code', $codes)
            ->exists();
    }

    // Mỗi pháp nhân / người đại diện / số điện thoại chỉ được miễn một lần (kể cả hồ sơ cũ đã xoá).
    private function alreadyUsed(Partner $partner): bool
    {
        return Partner::withTrashed()->where('id', '!=', $partner->id)->whereNotNull('fee_free_until')
            ->where(function ($query) use ($partner) {
                $any = false;
                foreach (['tax_code', 'representative_id_number', 'phone'] as $field) {
                    if (filled($partner->{$field})) {
                        $query->orWhere($field, $partner->{$field});
                        $any = true;
                    }
                }
                if (! $any) {
                    $query->whereRaw('1 = 0');
                }
            })->exists();
    }

    // ───────────────────────── Cấp / thu hồi ─────────────────────────

    /** Bắt đầu thời gian miễn phí từ $from (lúc hợp đồng có hiệu lực). @return Carbon mốc hết miễn phí */
    public function grant(Partner $partner, \DateTimeInterface $from, User $by): Carbon
    {
        $until = Carbon::instance($from)->addMonthsNoOverflow($this->months());
        $partner->forceFill(['fee_free_until' => $until])->saveQuietly();

        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id,
            'note' => 'Miễn phí tháng đầu: không hoa hồng, không bắt nạp ký quỹ đến ' . $until->format('d/m/Y H:i') . '.',
        ]);

        return $until;
    }

    /** Kết thúc sớm thời gian miễn phí (vd phát sinh chi nhánh ở tỉnh phải ký quỹ ngay): ký quỹ áp dụng ngay, đơn tạo từ giờ tính hoa hồng. */
    public function endNow(Partner $partner, string $reason, ?User $by = null): void
    {
        if (! $partner->isInFeeFreePeriod()) {
            return;
        }

        $partner->forceFill(['fee_free_until' => now()])->saveQuietly();

        if ($partner->escrow_min_amount) {
            $partner->forceFill(['escrow_enforced_from' => now()])->saveQuietly();
        }

        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by?->id,
            'note' => 'Kết thúc sớm thời gian miễn phí tháng đầu: ' . $reason,
        ]);

        $this->notifier->send($partner, 'escrow_trial_ended', 'Đã hết thời gian miễn phí', $reason . ' Hoa hồng và ký quỹ áp dụng từ bây giờ; vui lòng nạp đủ ký quỹ tối thiểu để tiếp tục bán phòng.', [], 'danger');
        $this->escrow->notifySuperAdmins('escrow_trial_ended', 'Kết thúc sớm thời gian miễn phí tháng đầu', ($partner->legal_name ?: $partner->name) . ': ' . $reason, $partner);
        $this->escrow->refreshState($partner->fresh());
    }

    // ───────────────────────── Chạy định kỳ (escrow:process) ─────────────────────────

    /**
     * Đối tác đang được miễn phí mà phát sinh chi nhánh ở tỉnh phải ký quỹ ngay thì dừng miễn phí (điều khoản miễn phí chỉ dành cho cơ sở ngoài các tỉnh đó).
     *
     * @return int số đối tác bị dừng miễn phí
     */
    public function revokeIfNowInImmediateProvince(): int
    {
        $codes = $this->immediateProvinceCodes();
        $count = 0;

        Partner::query()->where('fee_free_until', '>', now())->get()->each(function (Partner $partner) use ($codes, &$count) {
            if ($this->hasBranchIn($partner, $codes)) {
                $this->endNow($partner, 'Đối tác có chi nhánh thuộc tỉnh/thành phải ký quỹ ngay.');
                $count++;
            }
        });

        return $count;
    }

    /**
     * Nhắc đối tác nạp ký quỹ trước khi hết miễn phí (mặc định 7 ngày và 1 ngày trước), mỗi mốc một lần, bỏ qua đối tác đã nạp đủ.
     *
     * @return int số thông báo đã gửi
     */
    public function sendReminders(): int
    {
        $days = $this->reminderDays();

        if ($days === []) {
            return 0;
        }

        $sent = 0;
        $horizon = now()->addDays($days[0]);

        Partner::query()->where('fee_free_until', '>', now())->where('fee_free_until', '<=', $horizon)->get()->each(function (Partner $partner) use ($days, &$sent) {
            $min = (int) ($partner->escrow_min_amount ?: 0);

            if ($min <= 0 || (int) $partner->escrow_balance >= $min) {
                return;
            }

            // Mốc hiện tại = mốc nhỏ nhất đã tới (còn ≤ N ngày). Các mốc lớn hơn bị bỏ qua nếu hệ thống tạm dừng một thời gian.
            $current = null;
            foreach ($days as $day) {
                if (now()->gte($partner->fee_free_until->copy()->subDays($day))) {
                    $current = $day;
                }
            }

            if ($current === null || ! Cache::add("trial_remind:{$partner->id}:{$partner->fee_free_until->timestamp}:{$current}", 1, now()->addDays(60))) {
                return;
            }

            $left = max(1, (int) ceil(now()->diffInHours($partner->fee_free_until, false) / 24));
            $this->notifier->send(
                $partner,
                'escrow_trial_ending',
                'Sắp hết thời gian miễn phí',
                "Thời gian miễn phí của bạn kết thúc ngày {$partner->fee_free_until->format('d/m/Y')} (còn khoảng {$left} ngày). Vui lòng nạp ký quỹ tối thiểu "
                . number_format($min, 0, ',', '.') . 'đ trước ngày đó (hiện còn thiếu ' . number_format(max(0, $min - (int) $partner->escrow_balance), 0, ',', '.')
                . 'đ). Sau ngày đó hoa hồng và ký quỹ áp dụng; chưa nạp đủ thì phòng bị tạm ngưng bán.',
                ['fee_free_until' => $partner->fee_free_until->toIso8601String()],
            );
            $sent++;
        });

        return $sent;
    }
}
