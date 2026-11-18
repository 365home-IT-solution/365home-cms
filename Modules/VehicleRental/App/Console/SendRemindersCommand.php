<?php

namespace Modules\VehicleRental\App\Console;

use Illuminate\Console\Command;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleRentalNotifier;

/**
 * Nhắc khách trước giờ nhận xe / trước hạn trả xe và báo khi quá hạn trả. Mỗi loại chỉ gửi 1 lần cho mỗi đơn (cờ
 * pickup_reminded_at / return_reminded_at / overdue_notified_at, đặt bằng UPDATE có điều kiện trước khi gửi để 2 lần chạy chồng
 * nhau không gửi trùng).
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'vehicle-rental:send-reminders';

    protected $description = 'Gửi nhắc nhận xe, nhắc trả xe và báo quá hạn trả xe (thuê xe)';

    public function handle(VehicleRentalNotifier $notifier): int
    {
        // Thông báo đang tắt: không chiếm cờ "đã nhắc", để khi bật lại các đơn vẫn được nhắc đúng hạn.
        if (! $notifier->enabled()) {
            $this->info('Thông báo thuê xe đang tắt — bỏ qua.');

            return self::SUCCESS;
        }

        $cfg = (array) config('vehiclerental.notifications');
        $now = now();

        $pickup = $this->each(
            VehicleBooking::query()->where('status', VehicleBooking::STATUS_CONFIRMED)->whereNull('pickup_reminded_at')
                ->where('start_at', '>', $now)->where('start_at', '<=', $now->copy()->addMinutes((int) ($cfg['pickup_reminder_minutes'] ?? 120))),
            'pickup_reminded_at', fn ($b) => $notifier->pickupReminder($b),
        );

        $return = $this->each(
            VehicleBooking::query()->where('status', VehicleBooking::STATUS_PICKED_UP)->whereNull('return_reminded_at')
                ->where('end_at', '>', $now)->where('end_at', '<=', $now->copy()->addMinutes((int) ($cfg['return_reminder_minutes'] ?? 60))),
            'return_reminded_at', fn ($b) => $notifier->returnReminder($b),
        );

        $overdue = $this->each(
            VehicleBooking::query()->where('status', VehicleBooking::STATUS_PICKED_UP)->whereNull('overdue_notified_at')
                ->where('end_at', '<=', $now->copy()->subMinutes((int) ($cfg['overdue_after_minutes'] ?? 15))),
            'overdue_notified_at', fn ($b) => $notifier->overdue($b),
        );

        $this->info("Nhắc nhận xe: {$pickup}, nhắc trả xe: {$return}, quá hạn trả: {$overdue}.");

        return self::SUCCESS;
    }

    private function each($query, string $flag, \Closure $send): int
    {
        $sent = 0;

        $query->with('vehicle.branch')->eachById(function (VehicleBooking $b) use ($flag, $send, &$sent) {
            // Chiếm cờ trước: chỉ tiến trình nào cập nhật được dòng (cờ còn null) mới gửi.
            $claimed = VehicleBooking::query()->whereKey($b->id)->whereNull($flag)->update([$flag => now()]);
            if ($claimed) {
                $send($b);
                $sent++;
            }
        });

        return $sent;
    }
}
