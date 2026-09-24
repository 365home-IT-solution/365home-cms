<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStockReturnItem extends Model
{
    // Giữ micro giây thật cho created_at — dùng làm tiêu chí sắp xếp phụ nếu sau này bổ sung "return"
    // vào VIEW warehouse_stock_movements, đồng nhất WarehouseStockOutItem.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'warehouse_stock_return_id',
        'warehouse_item_id',
        'warehouse_stock_out_item_id',
        'quantity',
        'note',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        // Chặn hoàn NHIỀU HƠN số đã thực xuất cho đúng dòng xuất đó — VD: xuất 2 chai, khách dùng 1,
        // chỉ được hoàn tối đa 1 (2 - số đã hoàn trước đó cho cùng dòng xuất). Không áp dụng nếu
        // không truy vết theo phiếu xuất nào (warehouse_stock_out_item_id rỗng).
        static::creating(function (WarehouseStockReturnItem $line) {
            $line->guardAgainstOverReturn();
        });

        static::updating(function (WarehouseStockReturnItem $line) {
            $line->guardAgainstOverReturn(excludeSelf: true);
        });

        static::created(function (WarehouseStockReturnItem $line) {
            $line->applyToStock((float) $line->quantity);
        });

        static::updated(function (WarehouseStockReturnItem $line) {
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

        static::deleted(function (WarehouseStockReturnItem $line) {
            $line->applyToStock(-(float) $line->quantity);
        });
    }

    private function guardAgainstOverReturn(bool $excludeSelf = false): void
    {
        if (empty($this->warehouse_stock_out_item_id)) {
            return;
        }

        $issuedQuantity = (float) (WarehouseStockOutItem::whereKey($this->warehouse_stock_out_item_id)->value('quantity') ?? 0);

        $alreadyReturned = (float) static::where('warehouse_stock_out_item_id', $this->warehouse_stock_out_item_id)
            ->when($excludeSelf && $this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->sum('quantity');

        $returnable = $issuedQuantity - $alreadyReturned;

        if ((float) $this->quantity > $returnable) {
            throw new \RuntimeException("Số lượng hoàn ({$this->quantity}) vượt quá số lượng còn có thể hoàn ({$returnable}) của dòng xuất này.");
        }
    }

    protected function applyToStock(float $delta, ?int $itemId = null): void
    {
        if ($delta === 0.0) {
            return;
        }

        WarehouseItem::whereKey($itemId ?? $this->warehouse_item_id)
            ->increment('quantity', $delta);
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
