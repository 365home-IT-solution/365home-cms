<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleRatePlan extends Model
{
    protected $table = 'rental_vehicle_rate_plans';

    public const UNIT_HOUR = 'hour';
    public const UNIT_DAY = 'day';
    /** Gói theo km: price = đồng/km, min_km = km tối thiểu; không tính theo thời gian. */
    public const UNIT_KM = 'km';

    protected $fillable = [
        'vehicle_id', 'name', 'unit', 'duration_hours', 'price', 'weekend_price', 'included_km', 'extra_km_price', 'min_km',
        'overtime_grace_minutes', 'overtime_hour_price', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'duration_hours'         => 'integer',
        'price'                  => 'integer',
        'weekend_price'          => 'integer',
        'included_km'            => 'integer',
        'extra_km_price'         => 'integer',
        'min_km'                 => 'integer',
        'overtime_grace_minutes' => 'integer',
        'overtime_hour_price'    => 'integer',
        'is_active'              => 'boolean',
        'sort_order'             => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
