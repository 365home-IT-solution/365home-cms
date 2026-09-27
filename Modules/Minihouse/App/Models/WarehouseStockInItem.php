<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dòng phiếu nhập — mirror WarehouseStockInItem (Home). Đây là 1 trong 4 "cửa" duy nhất được phép
// làm thay đổi WarehouseItem::quantity (3 cửa còn lại: StockOutItem/StockCheckItem/
// StockReturnItem) — LUÔN qua increment()/decrement() thuần (query builder), KHÔNG bao giờ qua
// save()/update() của WarehouseItem, để không tự kích hoạt nhầm WarehouseItem::booted() (log điều
// chỉnh tay) cho 1 thay đổi ĐÃ có chứng từ theo dõi riêng ở đây.
class WarehouseStockInItem extends Model
{
    protected $table = 'minihouse_warehouse_stock_in_items';

    protected $fillable = ['warehouse_stock_in_id', 'warehouse_item_id', 'quantity', 'unit_price', 'amount', 'note'];

    protected $casts = [
        'quantity'   => 'float',
        'unit_price' => 'float',
        'amount'     => 'float',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::saving(function (WarehouseStockInItem $line) {
            $line->amount = round((float) $line->quantity * (float) $line->unit_price, 2);
        });

        static::created(function (WarehouseStockInItem $line) {
            $line->applyToStock((float) $line->quantity);
            $line->stockIn?->recalculateTotal();
        });

        static::updated(function (WarehouseStockInItem $line) {
            $originalItemId = $line->getOriginal('warehouse_item_id');
            $originalQty    = (float) $line->getOriginal('quantity');

            if ($originalItemId !== $line->warehouse_item_id) {
                WarehouseItem::whereKey($originalItemId)->decrement('quantity', $originalQty);
                $line->applyToStock((float) $line->quantity);
            } else {
                $delta = (float) $line->quantity - $originalQty;

                if ($delta !== 0.0) {
                    $line->applyToStock($delta);
                }
            }

            $line->stockIn?->recalculateTotal();
        });

        static::deleted(function (WarehouseStockInItem $line) {
            $line->applyToStock(-(float) $line->quantity);
            $line->stockIn?->recalculateTotal();
        });
    }

    private function applyToStock(float $delta): void
    {
        if ($delta === 0.0) {
            return;
        }

        if ($delta > 0) {
            WarehouseItem::whereKey($this->warehouse_item_id)->increment('quantity', $delta);
        } else {
            WarehouseItem::whereKey($this->warehouse_item_id)->decrement('quantity', abs($delta));
        }
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockIn::class, 'warehouse_stock_in_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
