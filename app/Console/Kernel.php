<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Command cũ - giữ nguyên
        $schedule->command('access-codes:auto-delete-expired')
            ->dailyAt('02:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping();

        // ✅ Thêm mới - tự động hủy đơn hàng hết hạn sau 15 phút
        $schedule->command('orders:expire-pending')
            ->everyMinute()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping(1)
            ->appendOutputTo(storage_path('logs/expire-orders.log'));

        $schedule->command('manual-lock-passwords:check-expired')
            ->hourly()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/expire-manual-lock-passwords.log'));

        $schedule->command('notifications:send-scheduled')
            ->everyMinute()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping(2)
            ->appendOutputTo(storage_path('logs/scheduled-notifications.log'));

        $schedule->command('notifications:checkin-reminder')
            ->everyFiveMinutes()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping(2)
            ->appendOutputTo(storage_path('logs/checkin-reminders.log'));

        $schedule->command('guests:link-registered')
            ->hourly()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/link-registered-guests.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
