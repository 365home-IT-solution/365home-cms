<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Dòng phiếu xuất — mirror WarehouseStockOutItem (Home). Chứa GUARD "không xuất vượt tồn khả dụng" —
// vừa chặn ở Filament form (client), vừa chặn LẠI ở đây (server, boot() event) làm lưới an toàn cuối
// cùng cho mọi đường ghi dữ liệu khác (API, tinker, artisan) — KHÔNG được phép bỏ qua.
class WarehouseStockOutItem extends Model
{
    protected $table = 'minihouse_warehouse_stock_out_items';

    protected $fillable = ['warehouse_stock_out_id', 'warehouse_item_id', 'reason', 'quantity', 'note'];

    protected $casts = [
        'quantity' => 'float',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (WarehouseStockOutItem $line) {
            $available = (float) (WarehouseItem::find($line->warehouse_item_id)?->quantity ?? 0);

            if ((float) $line->quantity > $available) {
                throw new \RuntimeException("Số lượng xuất ({$line->quantity}) vượt quá tồn kho khả dụng ({$available}).");
            }
        });

        static::updating(function (WarehouseStockOutItem $line) {
            $currentStock = (float) (WarehouseItem::find($line->warehouse_item_id)?->quantity ?? 0);

            // Đổi vật tư khác thì tồn khả dụng là CỦA VẬT TƯ MỚI (không cộng lại gì) — nếu vẫn cùng 1
            // vật tư, cộng lại đúng số lượng dòng này ĐANG giữ trước khi sửa (nó đã bị trừ vào tồn từ
            // lúc tạo, phải "trả tạm" lại mới so sánh đúng "còn xuất thêm được bao nhiêu").
            $originalItemId = $line->getOriginal('warehouse_item_id');
            $available = $originalItemId === $line->warehouse_item_id
                ? $currentStock + (float) $line->getOriginal('quantity')
                : $currentStock;

            if ((float) $line->quantity > $available) {
                throw new \RuntimeException("Số lượng xuất ({$line->quantity}) vượt quá tồn kho khả dụng ({$available}).");
            }
        });

        static::created(function (WarehouseStockOutItem $line) {
            WarehouseItem::whereKey($line->warehouse_item_id)->decrement('quantity', (float) $line->quantity);
        });

        static::updated(function (WarehouseStockOutItem $line) {
            $originalItemId = $line->getOriginal('warehouse_item_id');
            $originalQty    = (float) $line->getOriginal('quantity');

            if ($originalItemId !== $line->warehouse_item_id) {
                WarehouseItem::whereKey($originalItemId)->increment('quantity', $originalQty);
                WarehouseItem::whereKey($line->warehouse_item_id)->decrement('quantity', (float) $line->quantity);
            } else {
                $delta = (float) $line->quantity - $originalQty;

                if ($delta > 0) {
                    WarehouseItem::whereKey($line->warehouse_item_id)->decrement('quantity', $delta);
                } elseif ($delta < 0) {
                    WarehouseItem::whereKey($line->warehouse_item_id)->increment('quantity', abs($delta));
                }
            }
        });

        static::deleted(function (WarehouseStockOutItem $line) {
            WarehouseItem::whereKey($line->warehouse_item_id)->increment('quantity', (float) $line->quantity);
        });
    }

    public function stockOut(): BelongsTo
    {
        return $this->belongsTo(WarehouseStockOut::class, 'warehouse_stock_out_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(WarehouseStockReturnItem::class, 'warehouse_stock_out_item_id');
    }

    public function returnedQuantity(): float
    {
        return (float) $this->returnItems()->sum('quantity');
    }

    public function remainingReturnable(): float
    {
        return max(0, round((float) $this->quantity - $this->returnedQuantity(), 2));
    }
}
