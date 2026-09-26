<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Nhật ký PHÁT HIỆN chỉnh tay số lượng tồn — mirror WarehouseItemAdjustment (Home). Chỉ GHI, không
// sửa/xoá — không cần LogsMinihouseActivity (bản thân bảng này ĐÃ LÀ 1 dạng log).
class WarehouseItemAdjustment extends Model
{
    public $timestamps = false;

    protected $table = 'minihouse_warehouse_item_adjustments';

    protected $fillable = ['warehouse_item_id', 'old_quantity', 'new_quantity', 'difference', 'note', 'created_by'];

    protected $casts = [
        'old_quantity' => 'float',
        'new_quantity' => 'float',
        'difference'   => 'float',
        'created_at'   => 'datetime',
    ];

    // Giữ microsecond thật (bảng có created_at TIMESTAMP(6)) — dùng làm khoá sắp xếp phụ ở VIEW
    // minihouse_warehouse_stock_movements khi nhiều dòng cùng thời điểm.
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
