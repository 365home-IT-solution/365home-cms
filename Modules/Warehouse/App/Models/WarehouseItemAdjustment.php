<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Log CHỈ GHI (không sửa/xoá qua UI) mỗi lần cột warehouse_items.quantity bị sửa TRỰC TIẾP qua
// trang "Sửa vật tư" — xem giải thích đầy đủ ở migration 2026_08_15_000006 và
// WarehouseItem::updated(). Được UNION vào VIEW warehouse_stock_movements (loại "adjustment") để
// hiện chung trong sổ nhật ký biến động của vật tư.
class WarehouseItemAdjustment extends Model
{
    use BelongsToPartner;

    public $timestamps = false;

    protected $fillable = [
        'partner_id',
        'warehouse_item_id',
        'old_quantity',
        'new_quantity',
        'difference',
        'note',
        'created_by',
    ];

    protected $casts = [
        'old_quantity' => 'float',
        'new_quantity' => 'float',
        'difference'   => 'float',
        'created_at'   => 'datetime',
    ];

    // Giữ micro giây thật khi ghi created_at (xem giải thích ở WarehouseStockInItem) — mặc định
    // Eloquent format Carbon bỏ phần .u dù cột DB đã là TIMESTAMP(6).
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
