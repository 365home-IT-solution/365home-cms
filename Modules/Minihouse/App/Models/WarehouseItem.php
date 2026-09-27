<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Danh mục vật tư tồn kho THEO TỪNG TOÀ NHÀ — mirror Modules\Minihouse\App\Models\WarehouseItem
// (Home), đổi partner_id+branch_id thành building_id.
//
// "quantity" LÀ CỘT SỐ DƯ CHẠY (running balance) — bị CỘNG/TRỪ trực tiếp bằng increment()/
// decrement() bên trong booted() của WarehouseStockInItem/StockOutItem/StockCheckItem/
// StockReturnItem (xem các model đó), KHÔNG BAO GIỜ tính lại từ lịch sử. VIEW
// minihouse_warehouse_stock_movements chỉ dựng lại lịch sử để XEM/KIỂM TRA, neo ngược về đúng cột
// này (xem migration create_minihouse_warehouse_stock_movements_view).
class WarehouseItem extends Model
{
    use ScopedToActiveBuildingId, LogsMinihouseActivity;

    protected $table = 'minihouse_warehouse_items';

    protected $fillable = [
        'building_id', 'sku', 'name', 'warehouse_category_id', 'warehouse_unit_id',
        'quantity', 'quantity_in_use', 'min_quantity', 'unit_price', 'description', 'status',
    ];

    protected $casts = [
        // float (không phải 'decimal:2') — decimal cast luôn ép hiện ".00", không phù hợp khi hiển
        // thị số lượng vật tư (VD "5" thay vì "5.00").
        'quantity'        => 'float',
        'quantity_in_use' => 'float',
        'min_quantity'    => 'float',
        'unit_price'      => 'float',
        'status'          => 'boolean',
    ];

    protected $appends = ['quantity_reserve'];

    protected static function booted(): void
    {
        // Sửa "quantity" qua save()/update() THÔNG THƯỜNG (VD trang "Sửa vật tư") — KHÁC hẳn các lệnh
        // increment()/decrement() nội bộ của phiếu nhập/xuất/kiểm kê/hoàn trả (query builder thuần,
        // KHÔNG bắn event Eloquent nên không lọt vào đây) — tự ghi 1 dòng vào
        // minihouse_warehouse_item_adjustments để phát hiện chỉnh tay ngoài luồng chứng từ.
        static::updated(function (WarehouseItem $item) {
            if (! $item->wasChanged('quantity')) {
                return;
            }

            WarehouseItemAdjustment::create([
                'warehouse_item_id' => $item->id,
                'old_quantity'      => $item->getOriginal('quantity'),
                'new_quantity'      => $item->quantity,
                'difference'        => round($item->quantity - (float) $item->getOriginal('quantity'), 2),
                'created_by'        => auth()->id(),
            ]);
        });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'building_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WarehouseCategory::class, 'warehouse_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(WarehouseUnit::class, 'warehouse_unit_id');
    }

    public function stockInItems(): HasMany
    {
        return $this->hasMany(WarehouseStockInItem::class, 'warehouse_item_id');
    }

    public function stockOutItems(): HasMany
    {
        return $this->hasMany(WarehouseStockOutItem::class, 'warehouse_item_id');
    }

    public function stockCheckItems(): HasMany
    {
        return $this->hasMany(WarehouseStockCheckItem::class, 'warehouse_item_id');
    }

    public function stockReturnItems(): HasMany
    {
        return $this->hasMany(WarehouseStockReturnItem::class, 'warehouse_item_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(WarehouseItemAdjustment::class, 'warehouse_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(WarehouseStockMovement::class, 'warehouse_item_id');
    }

    public function isLowStock(): bool
    {
        return $this->min_quantity > 0 && $this->quantity <= $this->min_quantity;
    }

    public function getQuantityReserveAttribute(): float
    {
        return round(max(0, (float) $this->quantity - (float) $this->quantity_in_use), 2);
    }
}
