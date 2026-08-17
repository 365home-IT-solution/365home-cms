<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStockCheckItem extends Model
{
    // Xem giải thích $dateFormat ở WarehouseStockInItem — giữ micro giây thật cho created_at, dùng
    // làm tiêu chí sắp xếp phụ trong VIEW warehouse_stock_movements.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'warehouse_stock_check_id',
        'warehouse_item_id',
        'system_quantity',
        'actual_quantity',
        'difference',
        'note',
        'handover_quantity',
        'handover_difference',
    ];

    protected $casts = [
        // 'float' thay vì 'decimal:2' — xem giải thích ở WarehouseStockIn::$casts.
        'system_quantity'     => 'float',
        'actual_quantity'     => 'float',
        'difference'          => 'float',
        'handover_quantity'   => 'float',
        'handover_difference' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        // GỘP CHUNG vào 1 closure saving() — saving() chạy TRƯỚC creating() trong vòng đời Eloquent
        // (thứ tự thật: saving → creating → INSERT → created → saved). Trước đây auto-fetch
        // system_quantity nằm ở creating() riêng, tách khỏi phép tính difference ở saving() —
        // khiến difference luôn tính với system_quantity=null (ép về 0) mỗi khi tạo dòng KHÔNG tự
        // truyền sẵn system_quantity (vd qua API/tinker) — sai lệch nghiêm trọng, chỉ tình cờ không
        // lộ ra vì form Filament luôn tự điền sẵn system_quantity ở phía client trước khi submit.
        static::saving(function (WarehouseStockCheckItem $line) {
            if ($line->system_quantity === null && $line->warehouse_item_id) {
                $line->system_quantity = WarehouseItem::whereKey($line->warehouse_item_id)->value('quantity') ?? 0;
            }

            $line->difference = round((float) $line->actual_quantity - (float) $line->system_quantity, 2);
        });

        // Điều chỉnh tồn kho về đúng số thực tế đếm được: quantity += (actual - system).
        static::created(function (WarehouseStockCheckItem $line) {
            $line->applyToStock((float) $line->difference);
        });

        static::updated(function (WarehouseStockCheckItem $line) {
            $originalDifference = (float) $line->getOriginal('difference');
            $originalItemId     = $line->getOriginal('warehouse_item_id');

            if ($originalItemId !== $line->warehouse_item_id) {
                $line->applyToStock(-$originalDifference, $originalItemId);
                $line->applyToStock((float) $line->difference);

                return;
            }

            $delta = (float) $line->difference - $originalDifference;
            if ($delta !== 0.0) {
                $line->applyToStock($delta);
            }
        });

        static::deleted(function (WarehouseStockCheckItem $line) {
            $line->applyToStock(-(float) $line->difference);
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

    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockCheck::class, 'warehouse_stock_check_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }
}
