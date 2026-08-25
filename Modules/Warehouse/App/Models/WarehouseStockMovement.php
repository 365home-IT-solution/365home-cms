<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Model CHỈ ĐỌC (backed bởi SQL VIEW `warehouse_stock_movements`, xem migration
// 2026_08_15_000003) — gộp lịch sử biến động tồn kho từ CẢ 3 nguồn (nhập/xuất/kiểm kê) thành 1 sổ
// nhật ký duy nhất cho từng vật tư, "balance_after" (tồn sau đó) đã tính sẵn trong VIEW. Không có
// create()/update()/delete() — mọi thay đổi tồn kho vẫn phải đi qua đúng
// WarehouseStockIn/Out/CheckItem như bình thường, model này chỉ để XEM LẠI lịch sử.
class WarehouseStockMovement extends Model
{
    use BelongsToPartner;

    protected $table = 'warehouse_stock_movements';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'quantity_change'   => 'float',
        'balance_after'     => 'float',
        'occurred_at'       => 'datetime',
        'entry_created_at'  => 'datetime',
    ];

    public const TYPE_LABELS = [
        'in'         => 'Nhập kho',
        'out'        => 'Xuất kho',
        'check'      => 'Kiểm kê',
        'adjustment' => 'Điều chỉnh thủ công',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
