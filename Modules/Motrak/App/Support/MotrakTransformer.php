<?php

namespace Modules\Motrak\App\Support;

use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Models\MotrakVehicleLocation;

/** Định dạng JSON dùng chung cho API GPS và các module khác. KHÔNG bao giờ chứa Token. */
class MotrakTransformer
{
    private const COMPASS = ['Bắc', 'Đông Bắc', 'Đông', 'Đông Nam', 'Nam', 'Tây Nam', 'Tây', 'Tây Bắc'];

    public static function headingLabel(?int $degrees): ?string
    {
        if ($degrees === null) {
            return null;
        }

        return self::COMPASS[(int) floor((((($degrees % 360) + 360) % 360) + 22.5) / 45) % 8];
    }

    public static function vehicle(MotrakVehicle $v): array
    {
        return [
            'id'                  => $v->id,
            'vehicle_id'          => $v->vehicle_id,
            'plate'               => $v->plate,
            'imei'                => $v->imei,
            'rental_vehicle_id'   => $v->rental_vehicle_id,
            'position'            => $v->hasPosition() ? [
                'latitude'        => $v->latitude,
                'longitude'       => $v->longitude,
                'google_maps_url' => $v->googleMapsUrl(),
            ] : null,
            'speed_kmh'           => $v->speed_kmh,
            'heading'             => $v->heading,
            'heading_label'       => self::headingLabel($v->heading),
            'kmage'               => $v->kmage,
            'day'                 => ['km' => $v->day_km, 'idle_seconds' => $v->day_idle_seconds, 'run_seconds' => $v->day_run_seconds],
            'address'             => $v->address,
            'administrative_area' => $v->administrative_area,
            'province'            => MotrakArea::province($v->administrative_area),
            'device_status'       => $v->device_status,
            'is_connected'        => $v->is_connected,
            'gps_time'            => $v->gps_time?->toIso8601String(),
            'age_seconds'         => $v->ageSeconds(),
            'connection_state'    => $v->connectionState(),
            'connection_label'    => $v->connectionLabel(),
            'is_stale'            => $v->hasPosition() ? $v->isStale() : null,
            'is_moving'           => $v->isMoving(),
            'last_synced_at'      => $v->last_synced_at?->toIso8601String(),
            'in_last_sync'        => $v->in_last_sync,
        ];
    }

    /** Vị trí hiện tại gọn + độ tươi dữ liệu (cho API /location). */
    public static function location(MotrakVehicle $v): array
    {
        return [
            'vehicle_id'       => $v->vehicle_id,
            'plate'            => $v->plate,
            'has_position'     => $v->hasPosition(),
            'latitude'         => $v->hasPosition() ? $v->latitude : null,
            'longitude'        => $v->hasPosition() ? $v->longitude : null,
            'google_maps_url'  => $v->googleMapsUrl(),
            'speed_kmh'        => $v->speed_kmh,
            'heading'          => $v->heading,
            'heading_label'    => self::headingLabel($v->heading),
            'address'          => $v->address,
            'administrative_area' => $v->administrative_area,
            'province'         => MotrakArea::province($v->administrative_area),
            'gps_time'         => $v->gps_time?->toIso8601String(),
            'age_seconds'      => $v->ageSeconds(),
            'connection_state' => $v->connectionState(),
            'connection_label' => $v->connectionLabel(),
            'is_moving'        => $v->isMoving(),
        ];
    }

    /**
     * Marker cho bản đồ (public/vendor/motrak/motrak-map.js). null nếu xe chưa có vị trí hợp lệ.
     * state: stale (dữ liệu quá cũ) > moving > disconnected (isConnected=false, dữ liệu còn mới) > stopped.
     */
    public static function marker(MotrakVehicle $v, ?string $url = null): ?array
    {
        if (! $v->hasPosition()) {
            return null;
        }

        [$state, $label] = match (true) {
            $v->isStale()                                  => ['stale', 'Dữ liệu quá cũ'],
            $v->isMoving() === true                        => ['moving', 'Đang di chuyển'],
            $v->connectionState() === MotrakVehicle::STATE_DISCONNECTED => ['disconnected', 'Đang dừng — mất kết nối (isConnected = false)'],
            default                                        => ['stopped', 'Đang dừng'],
        };

        return [
            'id'              => $v->id,
            'plate'           => $v->plate,
            'lat'             => $v->latitude,
            'lng'             => $v->longitude,
            'speed'           => $v->speed_kmh,
            'state'           => $state,
            'state_label'     => $label,
            'address'         => $v->address,
            'area'            => $v->administrative_area,
            'gps_time'        => $v->gps_time?->format('H:i:s d/m/Y'),
            'google_maps_url' => $v->googleMapsUrl(),
            'url'             => $url,
        ];
    }

    public static function point(MotrakVehicleLocation $p): array
    {
        return [
            'latitude'            => $p->latitude,
            'longitude'           => $p->longitude,
            'google_maps_url'     => $p->googleMapsUrl(),
            'speed_kmh'           => $p->speed_kmh,
            'heading'             => $p->heading,
            'kmage'               => $p->kmage,
            'address'             => $p->address,
            'administrative_area' => $p->administrative_area,
            'is_connected'        => $p->is_connected,
            'gps_time'            => $p->gps_time?->toIso8601String(),
        ];
    }

    public static function syncLog(MotrakSyncLog $l): array
    {
        return [
            'id'             => $l->id,
            'sync_type'      => $l->sync_type,
            'status'         => $l->status,
            'total_vehicles' => $l->total_vehicles,
            'success_count'  => $l->success_count,
            'failed_count'   => $l->failed_count,
            'new_locations'  => $l->new_locations,
            'error_type'     => $l->error_type,
            'http_status'    => $l->http_status,
            'error_message'  => $l->error_message,
            'triggered_by'   => $l->triggered_by,
            'started_at'     => $l->started_at?->toIso8601String(),
            'completed_at'   => $l->completed_at?->toIso8601String(),
            'duration_ms'    => $l->durationMs(),
        ];
    }

    /** Ghi chú bắt buộc kèm mọi phản hồi lịch sử: tránh hiểu nhầm đây là lịch sử đầy đủ của Motrak. */
    public const HISTORY_NOTE = 'Chỉ gồm các điểm 365Home đã đồng bộ và lưu theo chính sách lưu trữ (xe đứng yên được lưu thưa); KHÔNG phải lịch sử đầy đủ từ Motrak.';
}
