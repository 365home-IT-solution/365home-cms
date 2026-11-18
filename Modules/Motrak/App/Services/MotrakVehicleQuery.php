<?php

namespace Modules\Motrak\App\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Motrak\App\Models\MotrakVehicle;

/**
 * Bộ lọc danh sách xe dùng chung cho CMS và API: tìm biển số/IMEI/vehicleId, trạng thái kết nối (kể cả "quá cũ" và "không có dữ liệu"),
 * đang chạy/đang dừng, thời gian cập nhật, ánh xạ xe cho thuê.
 */
class MotrakVehicleQuery
{
    /** @param  array{q?: ?string, state?: ?string, moving?: ?string, updated_within_minutes?: ?int, linked?: ?string}  $f */
    public static function apply(Builder $query, array $f): Builder
    {
        $staleBefore = now()->subMinutes((int) config('motrak.freshness.stale_after_minutes', 10));
        $movingKmh = (int) config('motrak.freshness.moving_speed_kmh', 3);

        if (! empty($f['q'])) {
            $q = trim((string) $f['q']);
            $norm = MotrakVehicle::normalizePlate($q);
            $query->where(function (Builder $w) use ($q, $norm) {
                $w->where('plate', 'like', "%{$q}%")->orWhere('imei', 'like', "%{$q}%")->orWhere('vehicle_id', $q);
                if ($norm !== '') {
                    $w->orWhere('plate_normalized', 'like', "%{$norm}%");
                }
            });
        }

        match ($f['state'] ?? null) {
            // Có dữ liệu còn mới VÀ isConnected = true / false (giá trị thô, chưa phải kết luận "offline hoàn toàn").
            MotrakVehicle::STATE_CONNECTED    => $query->whereNotNull('gps_time')->where('gps_time', '>=', $staleBefore)->where('is_connected', true),
            MotrakVehicle::STATE_DISCONNECTED => $query->whereNotNull('gps_time')->where('gps_time', '>=', $staleBefore)->where('is_connected', false),
            MotrakVehicle::STATE_STALE        => $query->whereNotNull('gps_time')->where('gps_time', '<', $staleBefore),
            MotrakVehicle::STATE_NO_DATA      => $query->where(fn (Builder $w) => $w->whereNull('gps_time')->orWhereNull('latitude')),
            default => null,
        };

        match ($f['moving'] ?? null) {
            'moving'  => $query->whereNotNull('gps_time')->where('gps_time', '>=', $staleBefore)->where('speed_kmh', '>=', $movingKmh),
            'stopped' => $query->whereNotNull('gps_time')->where('gps_time', '>=', $staleBefore)->where(fn (Builder $w) => $w->whereNull('speed_kmh')->orWhere('speed_kmh', '<', $movingKmh)),
            default => null,
        };

        if (! empty($f['updated_within_minutes'])) {
            $query->where('gps_time', '>=', now()->subMinutes((int) $f['updated_within_minutes']));
        }

        match ($f['linked'] ?? null) {
            'yes' => $query->whereNotNull('rental_vehicle_id'),
            'no'  => $query->whereNull('rental_vehicle_id'),
            default => null,
        };

        return $query;
    }
}
