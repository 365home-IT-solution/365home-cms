<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// "Sổ kho" (kardex) — model đọc TỪ VIEW minihouse_warehouse_stock_movements (mirror
// WarehouseStockMovement bên Home), KHÔNG CÓ create/update/delete nào — mọi thay đổi tồn kho THẬT
// phải đi qua WarehouseStockIn/Out/Check/ReturnItem (xem các model đó). "id" là chuỗi ghép
// ("in-123", "out-45"...), không phải số tự tăng.
class WarehouseStockMovement extends Model
{
    use ScopedToActiveBuildingId;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPE_RETURN = 'return';

    public const TYPE_CHECK = 'check';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_LABELS = [
        self::TYPE_IN         => 'Nhập kho',
        self::TYPE_OUT        => 'Xuất kho',
        self::TYPE_RETURN     => 'Hoàn trả kho',
        self::TYPE_CHECK      => 'Kiểm kê',
        self::TYPE_ADJUSTMENT => 'Điều chỉnh thủ công',
    ];

    protected $table = 'minihouse_warehouse_stock_movements';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'quantity_change'  => 'float',
        'balance_after'    => 'float',
        'occurred_at'      => 'datetime',
        'entry_created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // "reason" chỉ có ý nghĩa với type=out — tra lại đúng dòng WarehouseStockOutItem qua id gốc
    // (VD "out-45" -> WarehouseStockOutItem #45) — không lưu cột riêng trong VIEW để tránh JOIN thêm
    // không cần thiết cho 4 nhánh còn lại.
    public function reason(): ?string
    {
        if ($this->type !== self::TYPE_OUT) {
            return null;
        }

        $id = (int) str($this->id)->after('out-');

        $reason = WarehouseStockOutItem::find($id)?->reason;

        return $reason ? (WarehouseStockOut::REASONS[$reason] ?? $reason) : null;
    }

    // Phòng liên quan (nếu có) — chỉ có ở type out/return, tra qua phiếu cha.
    public function product(): ?array
    {
        $room = match ($this->type) {
            self::TYPE_OUT    => WarehouseStockOutItem::find((int) str($this->id)->after('out-'))?->stockOut?->room,
            self::TYPE_RETURN => WarehouseStockReturnItem::find((int) str($this->id)->after('return-'))?->stockReturn?->room,
            default           => null,
        };

        return $room ? ['id' => $room->id, 'name' => $room->code] : null;
    }
}
