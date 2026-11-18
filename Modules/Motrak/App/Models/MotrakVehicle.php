<?php

namespace Modules\Motrak\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Motrak\App\Support\MotrakCoordinates;

/**
 * Phương tiện trên Motrak kèm vị trí MỚI NHẤT. Biển số, vehicleId do Motrak cấp; IMEI và ánh xạ xe cho thuê do quản trị viên nhập.
 */
class MotrakVehicle extends Model
{
    protected $table = 'motrak_vehicles';

    // Trạng thái kết nối hiển thị (KHÔNG suy ra "offline" chỉ từ isConnected = false khi chưa xác minh quy tắc của Motrak).
    public const STATE_NO_DATA = 'no_data';
    public const STATE_STALE = 'stale';
    public const STATE_CONNECTED = 'connected';
    public const STATE_DISCONNECTED = 'disconnected';
    public const STATE_UNKNOWN = 'unknown';

    public const STATE_LABELS = [
        self::STATE_NO_DATA      => 'Không có dữ liệu',
        self::STATE_STALE        => 'Dữ liệu quá cũ',
        self::STATE_CONNECTED    => 'Đang kết nối',
        self::STATE_DISCONNECTED => 'Mất kết nối',
        self::STATE_UNKNOWN      => 'Chưa rõ',
    ];

    protected $fillable = [
        'vehicle_id', 'plate', 'plate_normalized', 'imei', 'rental_vehicle_id',
        'latitude', 'longitude', 'speed_kmh', 'heading', 'kmage', 'day_km', 'day_idle_seconds', 'day_run_seconds',
        'address', 'administrative_area', 'device_status', 'is_connected', 'gps_time', 'utc_ticks', 'device_time_raw',
        'last_stopped_at', 'raw_data', 'last_synced_at', 'in_last_sync', 'last_history_at', 'first_seen_at',
    ];

    protected $casts = [
        'latitude'          => 'float',
        'longitude'         => 'float',
        'speed_kmh'         => 'integer',
        'heading'           => 'integer',
        'kmage'             => 'float',
        'day_km'            => 'float',
        'device_status'     => 'integer',
        'is_connected'      => 'boolean',
        'in_last_sync'      => 'boolean',
        'gps_time'          => 'datetime',
        'last_stopped_at'   => 'datetime',
        'last_synced_at'    => 'datetime',
        'last_history_at'   => 'datetime',
        'first_seen_at'     => 'datetime',
        'utc_ticks'         => 'integer',
        'device_time_raw'   => 'integer',
        'rental_vehicle_id' => 'integer',
        'raw_data'          => 'array',
    ];

    /** Biển số chuẩn hoá: chữ hoa, bỏ mọi ký tự không phải chữ/số ("65A 41445" = "65A-414.45" = "65a41445"). */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    public function locations(): HasMany
    {
        return $this->hasMany(MotrakVehicleLocation::class, 'motrak_vehicle_id');
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && $this->gps_time !== null;
    }

    public function ageSeconds(): ?int
    {
        return $this->gps_time ? max(0, now()->getTimestamp() - $this->gps_time->getTimestamp()) : null;
    }

    public function isStale(): bool
    {
        $age = $this->ageSeconds();

        return $age !== null && $age > (int) config('motrak.freshness.stale_after_minutes', 10) * 60;
    }

    /** no_data | stale | connected | disconnected | unknown (xem hằng số STATE_*). */
    public function connectionState(): string
    {
        if (! $this->hasPosition()) {
            return self::STATE_NO_DATA;
        }
        if ($this->isStale()) {
            return self::STATE_STALE;
        }

        return match ($this->is_connected) {
            true    => self::STATE_CONNECTED,
            false   => self::STATE_DISCONNECTED,
            default => self::STATE_UNKNOWN,
        };
    }

    public function connectionLabel(): string
    {
        return self::STATE_LABELS[$this->connectionState()];
    }

    /** Đang di chuyển: có dữ liệu còn mới và tốc độ từ ngưỡng config motrak.freshness.moving_speed_kmh. Dữ liệu cũ/không có → null (chưa biết). */
    public function isMoving(): ?bool
    {
        if (! $this->hasPosition() || $this->isStale()) {
            return null;
        }

        return ($this->speed_kmh ?? 0) >= (int) config('motrak.freshness.moving_speed_kmh', 3);
    }

    public function googleMapsUrl(): ?string
    {
        return $this->hasPosition() ? MotrakCoordinates::googleMapsUrl($this->latitude, $this->longitude) : null;
    }

    public function scopeLinkedToRental(Builder $q): Builder
    {
        return $q->whereNotNull('rental_vehicle_id');
    }
}
