<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Khoảng thời gian khoá xe (bảo dưỡng, sửa chữa, đăng kiểm...) — xe không cho thuê trong khoảng này. */
class VehicleBlock extends Model
{
    protected $table = 'rental_vehicle_blocks';

    protected $fillable = ['vehicle_id', 'start_at', 'end_at', 'reason', 'created_by'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }
}
