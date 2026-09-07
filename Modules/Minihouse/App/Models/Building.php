<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuilding;

class Building extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuilding;
    use LogsMinihouseActivity;

    protected $table = 'minihouse_buildings';

    protected $fillable = [
        'name', 'address', 'province', 'ward',
        'electric_unit_price', 'water_unit_price',
        'note', 'image',
    ];

    // float — tránh cast 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "2500.00") dù giá trị là số nguyên.
    protected $casts = [
        'electric_unit_price' => 'float',
        'water_unit_price'    => 'float',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function surcharges(): HasMany
    {
        return $this->hasMany(Surcharge::class);
    }

    // Building tự thân LÀ toà nhà — không có building_id/room_id/contract_id để LogsMinihouseActivity
    // tự suy ra như các model khác.
    protected function activityBuildingId(): ?int
    {
        return $this->id;
    }
}
