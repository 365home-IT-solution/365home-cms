<?php

namespace Modules\VehicleRental\App\Services;

use Modules\Motrak\App\Contracts\HistoryPriorityResolver;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;

/**
 * Xe đang trong chuyến thuê (picked_up) hoặc vừa trả xe trong khoảng config vehiclerental.gps.return_hold_minutes → Motrak lưu MỌI điểm GPS mới
 * (độ phân giải đầy đủ theo chu kỳ đồng bộ) để dựng hành trình làm bằng chứng. Xe khác lưu thưa theo chính sách chung.
 */
class RentalHistoryPriority implements HistoryPriorityResolver
{
    public function needsFullHistory(MotrakVehicle $vehicle): bool
    {
        if (! $vehicle->rental_vehicle_id) {
            return false;
        }

        $hold = (int) config('vehiclerental.gps.return_hold_minutes', 30);

        return VehicleBooking::query()->where('vehicle_id', $vehicle->rental_vehicle_id)->where(function ($q) use ($hold) {
            $q->where('status', VehicleBooking::STATUS_PICKED_UP)
                ->orWhere(fn ($w) => $w->where('actual_end_at', '>=', now()->subMinutes($hold))->whereNotNull('actual_end_at')
                    ->whereIn('status', [VehicleBooking::STATUS_RETURNED, VehicleBooking::STATUS_SETTLED]));
        })->exists();
    }
}
