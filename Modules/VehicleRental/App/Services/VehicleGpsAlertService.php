<?php

namespace Modules\VehicleRental\App\Services;

use Illuminate\Support\Str;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBlock;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleGpsAlert;

/**
 * Cảnh báo GPS cho xe thuê đã ghép thiết bị. Quy tắc (ngưỡng ở config vehiclerental.gps — ngưỡng nội bộ, không phải quy định pháp luật):
 *  Xe ĐANG THUÊ (đơn picked_up):
 *   - out_of_area: khu vực hành chính (do Motrak trả) không nằm trong danh sách cho phép (danh sách rỗng = tắt).
 *   - speeding: tốc độ vượt ngưỡng theo loại xe.
 *   - offline_during_rental: mất tín hiệu GPS quá offline_after_minutes (nghi tháo/ngắt thiết bị).
 *  Xe KHÔNG có đơn đang thuê:
 *   - unauthorized_use: đang chạy từ tốc độ tối thiểu VÀ đã rời chi nhánh quá khoảng cách tối thiểu (bỏ qua xe bảo dưỡng/khoá, hoặc sắp tới giờ giao).
 *  Khi trả xe: return_far_from_branch; km_discrepancy (xem VehicleHandoverService).
 * Chống báo lặp bằng thời gian chờ (cooldown) theo từng loại; chỉ dùng dữ liệu còn mới, riêng offline_during_rental dựa vào độ cũ của dữ liệu.
 */
class VehicleGpsAlertService
{
    public function __construct(private VehicleGpsService $gps)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('vehiclerental.gps.alerts_enabled', true) && $this->gps->available();
    }

    /** Đánh giá toàn bộ xe thuê đã ghép GPS (lệnh định kỳ). @return int số cảnh báo mới */
    public function evaluateAll(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        return $this->evaluateTrackers(MotrakVehicle::query()->whereNotNull('rental_vehicle_id')->get());
    }

    /** Đánh giá các thiết bị có điểm GPS mới (sau mỗi lần đồng bộ). @param list<int> $motrakVehicleIds @return int */
    public function evaluateVehicles(array $motrakVehicleIds): int
    {
        if (! $this->enabled() || $motrakVehicleIds === []) {
            return 0;
        }

        return $this->evaluateTrackers(MotrakVehicle::query()->whereIn('id', $motrakVehicleIds)->whereNotNull('rental_vehicle_id')->get());
    }

    /** @param  iterable<MotrakVehicle>  $trackers */
    private function evaluateTrackers(iterable $trackers): int
    {
        $count = 0;
        foreach ($trackers as $tracker) {
            $vehicle = Vehicle::query()->find($tracker->rental_vehicle_id);
            if ($vehicle) {
                $count += count($this->evaluate($vehicle, $tracker));
            }
        }

        return $count;
    }

    /** @return list<VehicleGpsAlert> các cảnh báo MỚI được tạo */
    public function evaluate(Vehicle $vehicle, MotrakVehicle $tracker): array
    {
        $created = [];
        $active = VehicleBooking::query()->where('vehicle_id', $vehicle->id)->where('status', VehicleBooking::STATUS_PICKED_UP)->latest('id')->first();
        $fresh = $tracker->hasPosition() && ! $tracker->isStale();
        $push = function (?VehicleGpsAlert $a) use (&$created) {
            if ($a) {
                $created[] = $a;
            }
        };

        if ($active) {
            if (! $fresh) {
                $limit = (int) $this->gps->cfg('offline_after_minutes', 30);
                $age = $tracker->ageSeconds();
                if ($tracker->hasPosition() ? ($age !== null && $age > $limit * 60) : true) {
                    $push($this->raise($vehicle, $active, $tracker, 'offline_during_rental', 'danger',
                        'Xe ' . $vehicle->plate_display . ' đang được thuê (' . $active->code . ') nhưng mất tín hiệu GPS '
                        . ($age !== null ? 'từ ' . (int) round($age / 60) . ' phút trước' : '(chưa từng có dữ liệu)') . ' — thiết bị có thể bị tháo hoặc ngắt nguồn.',
                        ['age_seconds' => $age]));
                }

                return $created;
            }

            $areas = (array) $this->gps->cfg('allowed_areas', []);
            if ($areas !== [] && filled($tracker->administrative_area) && ! $this->areaAllowed($tracker->administrative_area, $areas)) {
                $push($this->raise($vehicle, $active, $tracker, 'out_of_area', 'danger',
                    "Xe {$vehicle->plate_display} (đơn {$active->code}) đang ở {$tracker->administrative_area}, ngoài khu vực cho phép (" . implode(', ', $areas) . ').',
                    ['area' => $tracker->administrative_area, 'address' => $tracker->address, 'allowed' => $areas]));
            }

            $max = (int) ($this->gps->cfg('max_speed_kmh', [])[$vehicle->type] ?? 0);
            if ($max > 0 && ($tracker->speed_kmh ?? 0) > $max) {
                $push($this->raise($vehicle, $active, $tracker, 'speeding', 'warning',
                    "Xe {$vehicle->plate_display} (đơn {$active->code}) đang chạy {$tracker->speed_kmh} km/h, vượt ngưỡng cảnh báo {$max} km/h.",
                    ['speed_kmh' => $tracker->speed_kmh, 'max' => $max, 'address' => $tracker->address]));
            }

            return $created;
        }

        // Không có đơn đang thuê.
        if ($fresh && $this->looksUnauthorized($vehicle, $tracker)) {
            $d = $this->gps->distanceToBranch($vehicle, $tracker->latitude, $tracker->longitude);
            $push($this->raise($vehicle, null, $tracker, 'unauthorized_use', 'danger',
                "Xe {$vehicle->plate_display} đang chạy {$tracker->speed_kmh} km/h cách chi nhánh khoảng {$d} m nhưng KHÔNG có đơn thuê đang giao.",
                ['speed_kmh' => $tracker->speed_kmh, 'distance_to_branch_m' => $d, 'address' => $tracker->address]));
        }

        return $created;
    }

    private function looksUnauthorized(Vehicle $vehicle, MotrakVehicle $tracker): bool
    {
        if ($vehicle->status !== Vehicle::STATUS_AVAILABLE || ($tracker->speed_kmh ?? 0) < (int) $this->gps->cfg('unauthorized_min_speed_kmh', 10)) {
            return false;
        }

        $d = $this->gps->distanceToBranch($vehicle, $tracker->latitude, $tracker->longitude);
        if ($d === null || $d < (int) $this->gps->cfg('unauthorized_min_distance_m', 300)) {
            return false;
        }

        // Đang khoá bảo dưỡng → thợ có thể chạy thử.
        if (VehicleBlock::query()->where('vehicle_id', $vehicle->id)->where('start_at', '<=', now())->where('end_at', '>', now())->exists()) {
            return false;
        }

        // Sắp tới (hoặc vừa qua) giờ giao xe: có thể nhân viên chưa kịp ghi biên bản giao.
        $early = (int) config('vehiclerental.pickup_early_minutes', 60);

        return ! VehicleBooking::query()->where('vehicle_id', $vehicle->id)->where('status', VehicleBooking::STATUS_CONFIRMED)
            ->whereBetween('start_at', [now()->subMinutes($early), now()->addMinutes($early)])->exists();
    }

    /** So khớp TỈNH/TP (đoạn cuối của "admin" Motrak) với danh sách cho phép, không phân biệt hoa thường/dấu/tiền tố "T.", "TP.", "Tỉnh", "Thành phố". */
    public function areaAllowed(string $area, array $allowed): bool
    {
        // "admin" của Motrak: "P. An Bình,TP. Cần Thơ" → so khớp theo TỈNH/TP ở đoạn cuối.
        $norm = fn (string $s) => \Modules\Motrak\App\Support\MotrakArea::normalize($s);
        $target = $norm(\Modules\Motrak\App\Support\MotrakArea::province($area) ?? $area);

        foreach ($allowed as $a) {
            if ($norm((string) $a) === $target) {
                return true;
            }
        }

        return false;
    }

    /** Tạo cảnh báo (nếu chưa báo trong thời gian chờ). KHÔNG gửi thông báo/push — chỉ ghi nhận để xem ở bản đồ GPS và danh sách cảnh báo. null nếu bị chặn bởi cooldown. */
    public function raise(Vehicle $vehicle, ?VehicleBooking $booking, ?MotrakVehicle $tracker, string $type, string $severity, string $message, array $data = []): ?VehicleGpsAlert
    {
        $cooldown = (int) ($this->gps->cfg('cooldown_minutes', [])[$type] ?? 60);
        if ($cooldown > 0 && VehicleGpsAlert::query()->where('vehicle_id', $vehicle->id)->where('type', $type)->where('created_at', '>=', now()->subMinutes($cooldown))->exists()) {
            return null;
        }

        $alert = VehicleGpsAlert::create([
            'vehicle_id' => $vehicle->id, 'booking_id' => $booking?->id, 'type' => $type, 'severity' => $severity, 'message' => $message,
            'data' => $data ?: null, 'motrak_vehicle_id' => $tracker?->id, 'gps_time' => $tracker?->gps_time,
        ]);
        return $alert;
    }

    public function acknowledge(VehicleGpsAlert $alert, ?string $userId): VehicleGpsAlert
    {
        if ($alert->acknowledged_at === null) {
            $alert->update(['acknowledged_at' => now(), 'acknowledged_by' => $userId]);
        }

        return $alert;
    }
}
