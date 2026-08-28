<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Services\NotificationFcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nhắc khách chưa điểm danh hôm nay, theo giờ cấu hình ở từng hạng thành viên
 * (MembershipTier::checkin_reminder_times). Chạy mỗi phút, chỉ gửi khi phút hiện tại khớp 1 giờ
 * đã cấu hình — số giờ cấu hình = số lần nhắc/ngày.
 */
class NotifyCheckinStreakReminderCommand extends Command
{
    protected $signature   = 'notifications:checkin-streak-reminder';
    protected $description = 'Nhắc khách chưa điểm danh hôm nay theo giờ cấu hình ở hạng thành viên';

    public function handle(NotificationFcmService $notifier): int
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh');
        $hm  = $now->format('H:i');
        $today = $now->toDateString();

        $tiers = MembershipTier::where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->where('checkin_reminder_enabled', true)
            ->get();

        $total = 0;

        foreach ($tiers as $tier) {
            $times = $tier->checkin_reminder_times ?: [];

            if (! in_array($hm, $times, true)) {
                continue;
            }

            $customers = Customer::where('membership_tier_id', $tier->id)
                ->where('status', Customer::STATUS_ACTIVE)
                ->whereDoesntHave('checkinCycles', fn ($q) => $q
                    ->whereHas('days', fn ($q2) => $q2->whereDate('checkin_date', $today))
                )
                ->get();

            if ($customers->isEmpty()) {
                continue;
            }

            // 1 NotificationFcm dùng chung cho cả đợt (giống NotificationFcmService::sendToMany
            // dùng cho gửi hàng loạt ở nơi khác) — tránh tạo hàng trăm row riêng lẻ mỗi lần khớp giờ.
            $notifier->sendToMany(
                $customers,
                'Đừng quên điểm danh hôm nay!',
                "Điểm danh ngay để nhận mã khuyến mãi dành riêng cho hạng {$tier->name}.",
                'checkin_streak_reminder',
                'users',
                ['tier_id' => (string) $tier->id],
                $tier->auto_issue_notify_url,
            );

            $total += $customers->count();
        }

        $this->info("Đã gửi {$total} nhắc nhở điểm danh.");

        return self::SUCCESS;
    }
}
