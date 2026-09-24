<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCheckinCycle;
use App\Models\CustomerCheckinDay;
use App\Models\MembershipTier;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Điểm danh hằng ngày để đủ điều kiện nhận mã khuyến mãi định kỳ của hạng thành viên (xem
 * MembershipTier::auto_issue_*). Thay thế hoàn toàn cơ chế cấp tự động lúc đăng nhập trước đây —
 * khách phải chủ động điểm danh qua POST /api/checkin.
 */
class CustomerCheckinService
{
    public function __construct(private readonly MembershipService $membershipService) {}

    /**
     * Lấy chu kỳ điểm danh đang mở của khách, tạo mới nếu chưa có. Trả về null nếu hạng hiện tại
     * của khách không bật auto_issue_enabled (tính năng điểm danh không áp dụng).
     */
    public function getOrCreateActiveCycle(Customer $customer): ?CustomerCheckinCycle
    {
        $tier = $this->eligibleTier($customer);

        if (! $tier) {
            return null;
        }

        // Khoá theo khách để 2 request song song (GET + POST lúc mở app) không cùng đóng chu kỳ
        // đứt rồi tạo 2 chu kỳ mới.
        return DB::transaction(function () use ($customer, $tier) {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            $today = Carbon::today();

            $active = CustomerCheckinCycle::where('customer_id', $customer->id)
                ->whereNull('completed_at')
                ->whereNull('broken_at')
                ->latest('id')
                ->first();

            if ($active && ! $this->isStreakBroken($active, $today)) {
                return $active;
            }

            // Chu kỳ chưa tick lượt nào (khách chỉ mở app xem lịch) thì dời ngày bắt đầu về hôm
            // nay, không đánh dấu đứt — tránh sinh 1 chu kỳ rỗng mỗi ngày khách mở app mà không tick.
            if ($active && $active->days_checked === 0) {
                $active->update(['cycle_start_date' => $today]);

                return $active;
            }

            if ($active) {
                $active->update(['broken_at' => now()]);
            }

            $cycle = CustomerCheckinCycle::create([
                'customer_id'         => $customer->id,
                'membership_tier_id'  => $tier->id,
                'days_required'       => max(1, (int) $tier->auto_issue_interval_days),
                'cycle_start_date'    => $today,
                'days_checked'        => 0,
            ]);

            $todayCheckin = CustomerCheckinDay::where('customer_id', $customer->id)
                ->whereDate('checkin_date', $today)
                ->with('cycle')
                ->first();

            if (! $todayCheckin) {
                return $cycle;
            }

            // Nếu chu kỳ trước vừa hoàn thành NGAY HÔM NAY (khách vừa tick đủ ngày), hôm nay đã bị
            // "dùng" cho chu kỳ cũ (unique customer_id+checkin_date chặn tick lại) — chu kỳ mới phải
            // bắt đầu từ ngày mai, không thì lịch hiển thị sai (tưởng hôm nay còn trống nhưng thực ra
            // không tick được nữa).
            if ($todayCheckin->cycle?->isCompleted()) {
                $cycle->update(['cycle_start_date' => $today->copy()->addDay()]);

                return $cycle;
            }

            // Dữ liệu cũ (trước khi có luật đứt chuỗi): hôm nay đã tick vào 1 chu kỳ quá hạn —
            // chuyển lượt đó sang chu kỳ mới làm Ngày 1 để khách không mất lượt hôm nay.
            $todayCheckin->cycle?->decrement('days_checked');
            $todayCheckin->update(['customer_checkin_cycle_id' => $cycle->id]);
            $cycle->update(['days_checked' => 1]);

            return $cycle;
        });
    }

    /**
     * Chuỗi điểm danh phải liên tục — ngày cần điểm danh tiếp theo luôn là cycle_start_date +
     * days_checked. Hôm nay đã qua ngày đó nghĩa là khách bỏ lỡ ít nhất 1 ngày: chu kỳ đứt, không
     * cho tick bù, lần kế tiếp làm lại từ Ngày 1.
     */
    private function isStreakBroken(CustomerCheckinCycle $cycle, CarbonInterface $today): bool
    {
        $nextDue = $cycle->cycle_start_date->copy()->addDays($cycle->days_checked);

        return $today->greaterThan($nextDue);
    }

    /**
     * Lịch điểm danh chu kỳ hiện tại, dùng cho GET /api/checkin (popup khi mở app).
     */
    public function calendar(Customer $customer): array
    {
        $cycle = $this->getOrCreateActiveCycle($customer);

        if (! $cycle) {
            return ['enabled' => false];
        }

        return $this->present($cycle);
    }

    /**
     * Điểm danh hôm nay cho khách. Idempotent trong cùng 1 ngày — gọi lại chỉ trả về trạng thái
     * hiện tại, không tick thêm hay cấp thêm mã.
     */
    public function checkin(Customer $customer): array
    {
        $cycle = $this->getOrCreateActiveCycle($customer);

        if (! $cycle) {
            return ['enabled' => false];
        }

        $today = Carbon::today();

        $alreadyChecked = CustomerCheckinDay::where('customer_id', $customer->id)
            ->whereDate('checkin_date', $today)
            ->exists();

        if ($alreadyChecked) {
            return $this->present($cycle);
        }

        return $this->tick($customer, $cycle, $today, 'app');
    }

    /**
     * Tick bù 1 ngày cho khách từ trang admin (CustomerCheckinResource) — dùng khi khách báo lỗi
     * không điểm danh được. Chỉ tick được hôm nay: chuỗi phải liên tục, ngày đã lỡ không tick bù
     * được (xem isStreakBroken()).
     */
    public function adminCheckin(Customer $customer): array
    {
        $date  = Carbon::today();
        $cycle = $this->getOrCreateActiveCycle($customer);

        if (! $cycle) {
            return ['enabled' => false];
        }

        $alreadyChecked = CustomerCheckinDay::where('customer_id', $customer->id)
            ->whereDate('checkin_date', $date)
            ->exists();

        if ($alreadyChecked) {
            return $this->present($cycle);
        }

        return $this->tick($customer, $cycle, $date, 'admin');
    }

    /**
     * Xoá chu kỳ hiện tại của khách (cascade xoá các ngày đã tick) để lần điểm danh kế tiếp bắt
     * đầu 1 chu kỳ mới từ đầu — dùng cho action "Reset chu kỳ" trên trang admin.
     */
    public function resetCycle(CustomerCheckinCycle $cycle): void
    {
        $cycle->delete();
    }

    private function tick(Customer $customer, CustomerCheckinCycle $cycle, CarbonInterface $date, string $source): array
    {
        CustomerCheckinDay::create([
            'customer_checkin_cycle_id' => $cycle->id,
            'customer_id'                => $customer->id,
            'checkin_date'                => $date,
            'source'                      => $source,
        ]);

        $cycle->increment('days_checked');
        $cycle->refresh();

        $voucherGranted = false;
        $coupon         = null;

        if ($cycle->days_checked >= $cycle->days_required) {
            $tier   = $cycle->membershipTier;
            $coupon = $this->membershipService->grantCheckinReward($customer, $tier);

            $cycle->update([
                'completed_at' => now(),
                'coupon_id'    => $coupon->id,
            ]);

            $voucherGranted = true;
        }

        $result = $this->present($cycle);

        $result['voucher_granted'] = $voucherGranted;
        $result['coupon']          = $coupon ? [
            'code'       => $coupon->code,
            'type'       => $coupon->type,
            'value'      => (float) $coupon->value,
            'expires_at' => $coupon->end_at?->toDateString(),
        ] : null;

        return $result;
    }

    private function present(CustomerCheckinCycle $cycle): array
    {
        $checkedDates = CustomerCheckinDay::where('customer_checkin_cycle_id', $cycle->id)
            ->pluck('checkin_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $today = Carbon::today()->toDateString();

        $days = [];
        $cursor = $cycle->cycle_start_date->copy();
        for ($i = 0; $i < $cycle->days_required; $i++) {
            $days[] = [
                'date'    => $cursor->toDateString(),
                'checked' => in_array($cursor->toDateString(), $checkedDates, true),
            ];
            $cursor->addDay();
        }

        $tier = $cycle->membershipTier;

        return [
            'enabled'          => true,
            'days_required'    => $cycle->days_required,
            'cycle_start_date' => $cycle->cycle_start_date->toDateString(),
            'days_checked'     => $cycle->days_checked,
            'checked_today'    => in_array($today, $checkedDates, true),
            'is_completed'     => $cycle->isCompleted(),
            'days'             => $days,
            'reward_preview'   => [
                'type'      => $tier->auto_issue_coupon_type,
                'value'     => (float) $tier->auto_issue_coupon_value,
                'value_max' => $tier->auto_issue_coupon_value_max ? (float) $tier->auto_issue_coupon_value_max : null,
            ],
            // Mặc định false/null — tick() ghi đè lại 2 khoá này khi thật sự vừa cấp mã. Luôn có
            // mặt trong mọi response (kể cả lần gọi idempotent) để app không phải tự xử lý field
            // có-khi-có-khi-không.
            'voucher_granted'  => false,
            'coupon'           => null,
        ];
    }

    private function eligibleTier(Customer $customer): ?MembershipTier
    {
        if (! $customer->membership_tier_id) {
            return null;
        }

        return MembershipTier::where('id', $customer->membership_tier_id)
            ->where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->where('auto_issue_coupon_value', '>', 0)
            ->first();
    }
}
