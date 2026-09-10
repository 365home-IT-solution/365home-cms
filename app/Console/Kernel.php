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

        $schedule->command('notifications:checkin-streak-reminder')
            ->everyMinute()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping(2)
            ->appendOutputTo(storage_path('logs/checkin-streak-reminder.log'));

        $schedule->command('guests:link-registered')
            ->hourly()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/link-registered-guests.log'));

        $schedule->command('housekeeping:mark-cleaning')
            ->everyFiveMinutes()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/housekeeping-mark-cleaning.log'));

        // Hold khung giờ (xem TimeslotHoldService) hết hạn TTL (5 phút) mà không ai chủ động bỏ
        // chọn/lưu đơn — dọn + báo real-time "released" định kỳ, tránh khách hàng kẹt mãi ở trạng
        // thái "đang khóa" (xem resources/js/echo-client.js, cập nhật DOM trực tiếp không tự làm
        // mới nếu không có sự kiện released).
        $schedule->command('timeslot-holds:release-expired')
            ->everyMinute()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping();

        // Khung giờ khoá dài hạn (BlockTimeslotModal/Dashboard "Lịch" — settings['blocked_dates'])
        // đã qua giờ kết thúc thì tự gỡ khoá — không cần admin tự tay dọn ngày đã qua.
        $schedule->command('timeslot-blocks:prune-expired')
            ->everyFiveMinutes()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping();

        // Safety-net: tự trả phòng khi khách quá giờ checkout mà không tự mở khoá lần 2.
        $schedule->command('orders:sync-lifecycle')
            ->everyMinute()
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping(1)
            ->appendOutputTo(storage_path('logs/sync-order-lifecycle.log'));

        // Bắt các mốc chuyển ngày của bảng giá (Tết, khuyến mãi, đối tác...) — áp bảng đang hiệu
        // lực hôm nay xuống products/room_time_slots, hoặc khôi phục về bảng mặc định khi hết hạn.
        $schedule->command('price-boards:sync-due')
            ->dailyAt('00:05')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/price-boards-sync.log'));

        // MiniHouse: lập hoá đơn hàng loạt — xem InvoiceGenerationService. Đổi từ "1 lần đầu tháng"
        // sang CHẠY HÀNG NGÀY (2026-09-08): toà "Theo ngày thuê" (Building::BILLING_CYCLE_ANNIVERSARY)
        // mỗi hợp đồng đến hạn 1 ngày khác nhau trong tháng, không chỉ mùng 1 — phải kiểm tra mỗi
        // ngày mới bắt đúng lúc. Toà "Theo tháng dương lịch" (mặc định, hành vi cũ) không bị ảnh
        // hưởng gì thêm: unique(contract_id, month) tự chặn tạo trùng, chạy thừa các ngày sau ngày 1
        // chỉ là no-op (skip vì đã có hoá đơn tháng đó). Vẫn lập tay được bất kỳ lúc nào qua nút "Lập
        // hoá đơn hàng loạt" ở trang Hoá đơn.
        $schedule->command('minihouse:generate-invoices')
            ->dailyAt('01:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-generate-invoices.log'));

        // MiniHouse: tự chuyển phòng "Đã đặt cọc" -> "Đã thuê" đúng ngày dọn vào — chạy TRƯỚC lập
        // hoá đơn (00:45 < 01:00), tránh trường hợp phòng vẫn hiện "Đã đặt cọc" khi hoá đơn tháng đó
        // đã được lập xong cho đúng ngày hôm nay.
        $schedule->command('minihouse:refresh-room-status')
            ->dailyAt('00:45')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-refresh-room-status.log'));

        // MiniHouse: tạo nhắc việc + gửi Zalo cho khách thuê có hợp đồng SẮP hết hạn (theo Building.
        // contract_expiry_reminder_days_before) — xem NotifyExpiringContractsCommand. Chạy TRƯỚC
        // minihouse:check-overdue-contracts để 2 lệnh không tranh nhau tạo Reminder cho cùng 1 hợp
        // đồng đúng ngày nó vừa chuyển từ "sắp hết hạn" sang "đã quá hạn".
        $schedule->command('minihouse:notify-expiring-contracts')
            ->dailyAt('06:15')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-notify-expiring-contracts.log'));

        // MiniHouse: tạo nhắc việc cho hợp đồng đã quá hạn (end_date đã qua) mà chưa thanh lý/gia
        // hạn — xem CheckOverdueContractsCommand. Chạy TRƯỚC minihouse:send-reminder-notifications
        // (07:00) để nhắc việc vừa tạo được gửi luôn trong cùng ngày, không phải đợi sang hôm sau.
        $schedule->command('minihouse:check-overdue-contracts')
            ->dailyAt('06:30')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-check-overdue-contracts.log'));

        // MiniHouse: gửi thông báo cho nhắc việc đến hạn/quá hạn — xem ReminderNotificationService.
        $schedule->command('minihouse:send-reminder-notifications')
            ->dailyAt('07:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-reminder-notifications.log'));

        // MiniHouse: nhắc Khai báo lưu trú đến hạn/quá hạn — xem ResidenceDeclarationService::
        // notifyDue(). Tách giờ khác nhắc việc thường ở trên vì đây là kênh riêng (gửi LẶP LẠI mỗi
        // ngày cho tới khi khai báo xong, không phải 1 lần như Reminder).
        $schedule->command('minihouse:notify-residence-declarations')
            ->dailyAt('08:00')
            ->timezone('Asia/Ho_Chi_Minh')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/minihouse-notify-residence-declarations.log'));
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
