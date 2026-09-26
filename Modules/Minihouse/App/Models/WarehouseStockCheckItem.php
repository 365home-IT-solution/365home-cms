<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dòng phiếu kiểm kê — mirror WarehouseStockCheckItem (Home). Đây là cơ chế ĐIỀU CHỈNH tồn kho về
// ĐÚNG số đếm thực tế: quantity của vật tư được CỘNG THÊM đúng "difference" (actual - system).
class WarehouseStockCheckItem extends Model
{
    protected $table = 'minihouse_warehouse_stock_check_items';

    protected $fillable = ['warehouse_stock_check_id', 'warehouse_item_id', 'system_quantity', 'actual_quantity', 'note', 'handover_quantity', 'handover_difference'];

    protected $casts = [
        'system_quantity' => 'float',
        'actual_quantity' => 'float',
        'difference'      => 'float',
        'handover_quantity'   => 'float',
        'handover_difference' => 'float',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::saving(function (WarehouseStockCheckItem $line) {
            // PHẢI ở saving() (chạy trước creating()) — "difference" cần system_quantity đã có giá
            // trị. "system_quantity" KHÔNG BAO GIỜ tin theo client truyền lên — luôn tự đọc tồn SỐNG
            // ngay lúc lưu, tránh gian lận/số liệu cũ.
            if (! $line->exists) {
                $line->system_quantity = (float) (WarehouseItem::find($line->warehouse_item_id)?->quantity ?? 0);
            }

            $line->difference = round((float) $line->actual_quantity - (float) $line->system_quantity, 2);
        });

        static::created(function (WarehouseStockCheckItem $line) {
            $line->applyToStock((float) $line->difference);
        });

        static::updated(function (WarehouseStockCheckItem $line) {
            $originalItemId = $line->getOriginal('warehouse_item_id');
            $originalDiff   = (float) $line->getOriginal('difference');

            if ($originalItemId !== $line->warehouse_item_id) {
                WarehouseItem::whereKey($originalItemId)->decrement('quantity', $originalDiff);
                $line->applyToStock((float) $line->difference);
            } else {
                $delta = (float) $line->difference - $originalDiff;

                if ($delta !== 0.0) {
                    $line->applyToStock($delta);
                }
            }
        });

        static::deleted(function (WarehouseStockCheckItem $line) {
            $line->applyToStock(-(float) $line->difference);
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

    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockCheck::class, 'warehouse_stock_check_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
