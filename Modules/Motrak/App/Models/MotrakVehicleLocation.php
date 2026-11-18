<?php

namespace Modules\Motrak\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Motrak\App\Support\MotrakCoordinates;

/**
 * Lịch sử vị trí do 365Home ĐÃ LƯU khi đồng bộ — KHÔNG phải lịch sử đầy đủ của Motrak (endpoint chỉ trả điểm mới nhất,
 * và chính sách lưu thưa với xe đứng yên). Duy nhất theo (xe, utc_ticks).
 */
class MotrakVehicleLocation extends Model
{
    public $timestamps = false;

    protected $table = 'motrak_vehicle_locations';

    protected $fillable = [
        'motrak_vehicle_id', 'latitude', 'longitude', 'speed_kmh', 'heading', 'kmage', 'address', 'administrative_area',
        'device_status', 'is_connected', 'gps_time', 'utc_ticks', 'created_at',
    ];

    protected $casts = [
        'latitude'     => 'float',
        'longitude'    => 'float',
        'speed_kmh'    => 'integer',
        'heading'      => 'integer',
        'kmage'        => 'float',
        'device_status' => 'integer',
        'is_connected' => 'boolean',
        'gps_time'     => 'datetime',
        'utc_ticks'    => 'integer',
        'created_at'   => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(MotrakVehicle::class, 'motrak_vehicle_id');
    }

    public function googleMapsUrl(): string
    {
        return (string) MotrakCoordinates::googleMapsUrl($this->latitude, $this->longitude);
    }
}
