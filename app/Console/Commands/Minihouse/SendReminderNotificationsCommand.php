<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Services\ReminderNotificationService;

// Gửi thông báo (chuông + bảng "notifications" dùng chung với Home, cùng Zalo ZNS/SMS/Portal) cho
// các Reminder đến hạn — logic thực tế nằm ở ReminderNotificationService::sendDue() (dùng chung với
// endpoint API kích hoạt thủ công POST /api/admin/minihouse/reminders/send-due, xem
// ReminderController::sendDue()), lệnh này chỉ là 1 trong 2 nơi gọi. Chạy hàng ngày (xem
// app/Console/Kernel.php).
class SendReminderNotificationsCommand extends Command
{
    protected $signature = 'minihouse:send-reminder-notifications';

    protected $description = 'Gửi thông báo cho các nhắc việc MiniHouse đến hạn/quá hạn (và nhắc đóng tiền lặp lại nếu vẫn chưa thanh toán)';

    public function handle(): int
    {
        $result = ReminderNotificationService::sendDue();

        $this->info("Đã gửi thông báo cho {$result['total']} nhắc việc ({$result['new']} mới, {$result['repeat']} lặp lại do chưa thanh toán).");

        return self::SUCCESS;
    }
}
