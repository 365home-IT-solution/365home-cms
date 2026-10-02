<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

// Chạy định kỳ (Kernel::schedule): báo hết hạn, nhắc trước hạn (30/7/3/1 ngày), tạo link gia hạn cho đối tác bật tự gia hạn,
// đóng yêu cầu thanh toán quá hạn. Hết hạn KHÔNG cần lệnh này để khoá — cổng kiểm soát tính trực tiếp theo expires_at.
class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Nhắc hạn, báo hết hạn và tạo link gia hạn cho gói dịch vụ đối tác';

    public function handle(SubscriptionService $service): int
    {
        $s = $service->processDaily();

        $this->info("Hết hạn báo: {$s['expired_notified']}, nhắc trước hạn: {$s['reminders']}, link gia hạn: {$s['renewal_links']}, thanh toán quá hạn đóng: {$s['payments_expired']}, giao dịch cũ đã dọn: {$s['payments_pruned']}");

        return self::SUCCESS;
    }
}
