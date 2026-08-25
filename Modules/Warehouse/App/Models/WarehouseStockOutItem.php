<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStockOutItem extends Model
{
    // Xem giải thích $dateFormat ở WarehouseStockInItem — giữ micro giây thật cho created_at, dùng
    // làm tiêu chí sắp xếp phụ trong VIEW warehouse_stock_movements.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'warehouse_stock_out_id',
        'warehouse_item_id',
        'reason',
        'quantity',
        'note',
    ];

    protected $casts = [
        // 'float' thay vì 'decimal:2' — xem giải thích ở WarehouseStockIn::$casts.
        'quantity' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        // Safety-net chống xuất âm kho cho các đường tạo/sửa KHÔNG đi qua validate của
        // WarehouseStockOutForm (vd: tinker, artisan command, API nội bộ...). Form đã tự chặn và
        // báo lỗi thân thiện hơn — đây chỉ là lớp bảo vệ cuối cùng ở tầng dữ liệu.
        static::creating(function (WarehouseStockOutItem $line) {
            $available = (float) (WarehouseItem::whereKey($line->warehouse_item_id)->value('quantity') ?? 0);

            if ((float) $line->quantity > $available) {
                throw new \RuntimeException("Số lượng xuất ({$line->quantity}) vượt quá tồn kho khả dụng ({$available}).");
            }
        });

        static::updating(function (WarehouseStockOutItem $line) {
            $originalQuantity = (float) $line->getOriginal('quantity');
            $originalItemId   = $line->getOriginal('warehouse_item_id');
            $currentStock     = (float) (WarehouseItem::whereKey($line->warehouse_item_id)->value('quantity') ?? 0);

            $available = $originalItemId === $line->warehouse_item_id
                ? $currentStock + $originalQuantity
                : $currentStock;

            if ((float) $line->quantity > $available) {
                throw new \RuntimeException("Số lượng xuất ({$line->quantity}) vượt quá tồn kho khả dụng ({$available}).");
            }
        });

        static::created(function (WarehouseStockOutItem $line) {
            $line->applyToStock(-(float) $line->quantity);
        });

        static::updated(function (WarehouseStockOutItem $line) {
            $originalQuantity = (float) $line->getOriginal('quantity');
            $originalItemId   = $line->getOriginal('warehouse_item_id');

            if ($originalItemId !== $line->warehouse_item_id) {
                $line->applyToStock($originalQuantity, $originalItemId);
                $line->applyToStock(-(float) $line->quantity);

                return;
            }

            $delta = (float) $line->quantity - $originalQuantity;
            if ($delta !== 0.0) {
                $line->applyToStock(-$delta);
            }
        });

        static::deleted(function (WarehouseStockOutItem $line) {
            $line->applyToStock((float) $line->quantity);
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

    public function stockOut(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockOut::class, 'warehouse_stock_out_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
