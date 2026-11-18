<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cảnh báo GPS của xe thuê (ra khỏi khu vực, vượt tốc độ, chạy khi không có đơn, mất tín hiệu khi đang thuê, trả xe sai chỗ, lệch km). */
class VehicleGpsAlert extends Model
{
    protected $table = 'rental_vehicle_gps_alerts';

    public const TYPES = [
        'out_of_area'            => 'Ra khỏi khu vực cho phép',
        'speeding'               => 'Vượt tốc độ',
        'unauthorized_use'       => 'Xe chạy khi không có đơn',
        'offline_during_rental'  => 'Mất tín hiệu GPS khi đang thuê',
        'return_far_from_branch' => 'Trả xe xa chi nhánh',
        'km_discrepancy'         => 'Lệch km GPS và odometer',
    ];

    protected $fillable = [
        'vehicle_id', 'booking_id', 'type', 'severity', 'message', 'data', 'motrak_vehicle_id', 'gps_time', 'acknowledged_at', 'acknowledged_by',
    ];

    protected $casts = [
        'data'            => 'array',
        'gps_time'        => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VehicleBooking::class, 'booking_id');
    }

    public function isOpen(): bool
    {
        return $this->acknowledged_at === null;
    }
}
