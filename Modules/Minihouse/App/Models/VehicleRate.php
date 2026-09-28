<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

// Bảng giá gửi xe THEO TOÀ NHÀ + LOẠI XE: phí tháng, số xe tối đa mỗi hợp đồng, tổng số chỗ của toà.
class VehicleRate extends Model
{
    use LogsMinihouseActivity;

    protected $table = 'minihouse_vehicle_rates';

    protected $fillable = ['building_id', 'vehicle_type', 'monthly_fee', 'max_per_contract', 'capacity'];

    protected $casts = [
        'monthly_fee'      => 'float',
        'max_per_contract' => 'integer',
        'capacity'         => 'integer',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    protected function activityLabel(): string
    {
        return (Vehicle::TYPES[$this->vehicle_type] ?? $this->vehicle_type) . ' — toà #' . $this->building_id;
    }
}
