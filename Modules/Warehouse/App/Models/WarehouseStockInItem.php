<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStockInItem extends Model
{
    // Mặc định Eloquent format Carbon thành 'Y-m-d H:i:s' (bỏ phần micro giây) khi lưu, dù cột DB đã
    // là TIMESTAMP(6) — khiến created_at mất hết độ phân giải cần thiết để làm tiêu chí sắp xếp phụ
    // (entry_created_at) trong VIEW warehouse_stock_movements khi nhiều dòng cùng "hòa" occurred_at.
    // Ghi rõ $dateFormat có phần .u để giữ micro giây thật khi ghi xuống DB.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'warehouse_stock_in_id',
        'warehouse_item_id',
        'quantity',
        'unit_price',
        'amount',
        'note',
    ];

    protected $casts = [
        // 'float' thay vì 'decimal:2' — xem giải thích ở WarehouseStockIn::$casts.
        'quantity'   => 'float',
        'unit_price' => 'float',
        'amount'     => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function (WarehouseStockInItem $line) {
            $line->amount = round((float) $line->quantity * (float) $line->unit_price, 2);
        });

        static::created(function (WarehouseStockInItem $line) {
            $line->applyToStock((float) $line->quantity);
        });

        static::updated(function (WarehouseStockInItem $line) {
            $originalQuantity = (float) $line->getOriginal('quantity');
            $originalItemId   = $line->getOriginal('warehouse_item_id');

            if ($originalItemId !== $line->warehouse_item_id) {
                $line->applyToStock(-$originalQuantity, $originalItemId);
                $line->applyToStock((float) $line->quantity);

                return;
            }

            $delta = (float) $line->quantity - $originalQuantity;
            if ($delta !== 0.0) {
                $line->applyToStock($delta);
            }
        });

        static::deleted(function (WarehouseStockInItem $line) {
            $line->applyToStock(-(float) $line->quantity);
        });

        static::saved(function (WarehouseStockInItem $line) {
            $line->stockIn?->recalculateTotal();
        });

        static::deleted(function (WarehouseStockInItem $line) {
            $line->stockIn?->recalculateTotal();
        });
    }

    protected function applyToStock(float $delta, ?int $itemId = null): void
    {
        if ($delta === 0.0) {
            return;
        }

        WarehouseItem::whereKey($itemId ?? $this->warehouse_item_id)
            ->increment('quantity', $delta);
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
