<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

// Đơn vị tính vật tư — DÙNG CHUNG mọi Toà nhà, mirror WarehouseUnit (Home).
class WarehouseUnit extends Model
{
    use LogsMinihouseActivity;

    protected $table = 'minihouse_warehouse_units';

    protected $fillable = ['name'];

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseItem::class, 'warehouse_unit_id');
    }
}
