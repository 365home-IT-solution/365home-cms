<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Services\ReminderNotificationService;

// Gửi thông báo (chuông + bảng "notifications" dùng chung với Home) cho các Reminder đến hạn hôm
// nay hoặc đã quá hạn, CHƯA xong và CHƯA từng gửi (notified_at null) — xem
// ReminderNotificationService. Chạy hàng ngày (xem app/Console/Kernel.php).
class SendReminderNotificationsCommand extends Command
{
    protected $signature = 'minihouse:send-reminder-notifications';

    protected $description = 'Gửi thông báo cho các nhắc việc MiniHouse đến hạn/quá hạn, chưa hoàn thành và chưa gửi trước đó';

    public function handle(): int
    {
        $reminders = Reminder::query()
            ->withoutGlobalScopes()
            ->where('is_done', false)
            ->whereNull('notified_at')
            ->whereDate('remind_date', '<=', now()->toDateString())
            ->get();

        foreach ($reminders as $reminder) {
            ReminderNotificationService::notify($reminder);
        }

        $this->info("Đã gửi thông báo cho {$reminders->count()} nhắc việc.");

        return self::SUCCESS;
    }
}
