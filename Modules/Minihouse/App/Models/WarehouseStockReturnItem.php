<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Dòng phiếu hoàn trả — mirror WarehouseStockReturnItem (Home). Hoàn trả LÀM TĂNG tồn kho (giống
// nhập kho). Có "warehouse_stock_out_item_id" = hoàn TRUY VẾT được (giới hạn không vượt số đã xuất -
// đã hoàn trước đó); NULL = hoàn KHÔNG TRUY VẾT (vật tư dư tìm thấy, không giới hạn).
class WarehouseStockReturnItem extends Model
{
    protected $table = 'minihouse_warehouse_stock_return_items';

    protected $fillable = ['warehouse_stock_return_id', 'warehouse_item_id', 'warehouse_stock_out_item_id', 'quantity', 'note'];

    protected $casts = [
        'quantity' => 'float',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(fn (WarehouseStockReturnItem $line) => $line->guardAgainstOverReturn());
        static::updating(fn (WarehouseStockReturnItem $line) => $line->guardAgainstOverReturn());

        static::created(function (WarehouseStockReturnItem $line) {
            WarehouseItem::whereKey($line->warehouse_item_id)->increment('quantity', (float) $line->quantity);
        });

        static::updated(function (WarehouseStockReturnItem $line) {
            $originalItemId = $line->getOriginal('warehouse_item_id');
            $originalQty    = (float) $line->getOriginal('quantity');

            if ($originalItemId !== $line->warehouse_item_id) {
                WarehouseItem::whereKey($originalItemId)->decrement('quantity', $originalQty);
                WarehouseItem::whereKey($line->warehouse_item_id)->increment('quantity', (float) $line->quantity);
            } else {
                $delta = (float) $line->quantity - $originalQty;

                if ($delta > 0) {
                    WarehouseItem::whereKey($line->warehouse_item_id)->increment('quantity', $delta);
                } elseif ($delta < 0) {
                    WarehouseItem::whereKey($line->warehouse_item_id)->decrement('quantity', abs($delta));
                }
            }
        });

        static::deleted(function (WarehouseStockReturnItem $line) {
            WarehouseItem::whereKey($line->warehouse_item_id)->decrement('quantity', (float) $line->quantity);
        });
    }

    private function guardAgainstOverReturn(): void
    {
        if (blank($this->warehouse_stock_out_item_id)) {
            return;
        }

        $stockOutItem = WarehouseStockOutItem::find($this->warehouse_stock_out_item_id);

        if (! $stockOutItem) {
            return;
        }

        $alreadyReturned = $stockOutItem->returnItems()
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
            ->sum('quantity');

        $returnable = round((float) $stockOutItem->quantity - (float) $alreadyReturned, 2);

        if ((float) $this->quantity > $returnable) {
            throw new \RuntimeException("Số lượng hoàn trả ({$this->quantity}) vượt quá số còn có thể hoàn ({$returnable}).");
        }
    }

    public function stockReturn(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockReturn::class, 'warehouse_stock_return_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function stockOutItem(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockOutItem::class, 'warehouse_stock_out_item_id');
    }
}
