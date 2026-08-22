<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Services\MembershipService;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoIssueTierCouponsCommand extends Command
{
    protected $signature   = 'membership:auto-issue-tier-coupons';
    protected $description = 'Tự động tạo mã khuyến mãi định kỳ + gửi thông báo cho từng hạng thành viên theo lịch riêng của hạng';

    public function handle(MembershipService $membershipService, NotificationFcmService $notificationService): int
    {
        $now = now();

        $tiers = MembershipTier::where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->whereNotNull('auto_issue_day_of_week')
            ->whereNotNull('auto_issue_time')
            ->get();

        foreach ($tiers as $tier) {
            $scheduledAt = $now->copy()
                ->startOfWeek(Carbon::SUNDAY)
                ->addDays((int) $tier->auto_issue_day_of_week)
                ->setTimeFromTimeString((string) $tier->auto_issue_time);

            if ($now->lt($scheduledAt)) {
                continue;
            }

            if ($tier->auto_issue_last_run_at) {
                $intervalWeeks = max(1, (int) $tier->auto_issue_interval_weeks);
                $nextDueAt     = $tier->auto_issue_last_run_at->copy()->addWeeks($intervalWeeks);

                if ($now->lt($nextDueAt)) {
                    continue;
                }
            }

            $result = $membershipService->runAutoIssueForTier($tier);
            $tier->update(['auto_issue_last_run_at' => now()]);

            if (empty($result['customer_ids'])) {
                $this->line("Hạng \"{$tier->name}\": không có khách nào để cấp mã.");

                continue;
            }

            $customers = Customer::whereIn('id', $result['customer_ids'])->get();

            $notificationService->sendToMany(
                $customers,
                $tier->auto_issue_notify_title ?: 'Ưu đãi dành cho hạng ' . $tier->name,
                $tier->auto_issue_notify_body ?: 'Bạn vừa nhận được một mã khuyến mãi mới dành riêng cho hạng thành viên của bạn. Kiểm tra ngay!',
                'membership_auto_coupon',
            );

            $this->info("Hạng \"{$tier->name}\": đã cấp {$result['coupon_count']} mã và gửi thông báo.");
        }

        return 0;
    }
}
