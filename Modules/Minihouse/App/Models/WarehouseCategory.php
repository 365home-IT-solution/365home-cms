<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

// Nhóm vật tư — DÙNG CHUNG mọi Toà nhà (không có building_id) — mirror
// Modules\Warehouse\App\Models\WarehouseCategory (Home), bỏ tầng đối tác vì MiniHouse không có.
class WarehouseCategory extends Model
{
    use LogsMinihouseActivity;

    protected $table = 'minihouse_warehouse_categories';

    protected $fillable = ['name'];

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseItem::class, 'warehouse_category_id');
    }
}
