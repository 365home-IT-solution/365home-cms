<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biên bản giao xe (pickup) hoặc nhận lại xe (return): ảnh, odometer, mức xăng, ghi chú. Mỗi đơn tối đa 1 biên bản mỗi loại. */
class VehicleHandover extends Model
{
    protected $table = 'rental_vehicle_handovers';

    public const TYPE_PICKUP = 'pickup';
    public const TYPE_RETURN = 'return';

    protected $fillable = [
        'booking_id', 'type', 'odometer_km', 'fuel_level', 'photos', 'notes', 'staff_id', 'handled_at',
        'gps_motrak_vehicle_id', 'gps_latitude', 'gps_longitude', 'gps_kmage', 'gps_time', 'gps_address', 'gps_fresh', 'gps_branch_distance_m',
    ];

    protected $casts = [
        'photos'      => 'array',
        'odometer_km' => 'integer',
        'fuel_level'  => 'integer',
        'handled_at'  => 'datetime',
        'gps_latitude'  => 'float',
        'gps_longitude' => 'float',
        'gps_kmage'     => 'float',
        'gps_time'      => 'datetime',
        'gps_fresh'     => 'boolean',
        'gps_branch_distance_m' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VehicleBooking::class, 'booking_id');
    }
}
