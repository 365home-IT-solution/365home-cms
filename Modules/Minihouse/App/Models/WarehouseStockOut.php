<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Phiếu xuất kho — mirror Modules\Warehouse\App\Models\WarehouseStockOut (Home). "reason" nằm ở TỪNG
// DÒNG (warehouse_stock_out_items.reason), KHÔNG ở phiếu — 1 phiếu có thể trộn nhiều lý do khác nhau.
class WarehouseStockOut extends Model
{
    use ScopedToActiveBuildingId, LogsMinihouseActivity;

    public const REASON_HOUSEKEEPING = 'housekeeping';

    public const REASON_TENANT_USAGE = 'tenant_usage';

    public const REASON_DAMAGED = 'damaged';

    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_HOUSEKEEPING => 'Dọn phòng / Vệ sinh',
        self::REASON_TENANT_USAGE => 'Sử dụng cho khách thuê / phòng',
        self::REASON_DAMAGED      => 'Hao hụt / Hư hỏng',
        self::REASON_OTHER        => 'Khác',
    ];

    protected $table = 'minihouse_warehouse_stock_outs';

    protected $fillable = ['building_id', 'code', 'room_id', 'issued_to', 'issued_at', 'note', 'created_by'];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WarehouseStockOut $stockOut) {
            if (blank($stockOut->code)) {
                $stockOut->code = self::generateCode();
            }

            if (blank($stockOut->created_by)) {
                $stockOut->created_by = auth()->id();
            }

            $stockOut->issued_at = now();
        });

        static::deleting(function (WarehouseStockOut $stockOut) {
            $stockOut->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        $last = static::withoutGlobalScope('activeBuilding')
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->value('id');

        return 'PX' . str_pad((string) (((int) $last) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'building_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(\Modules\Minihouse\App\Models\Room::class, 'room_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockOutItem::class, 'warehouse_stock_out_id');
    }

    // Chỉ để withCount/withExists KHÔNG PHẢI eager-load hết items — dùng ở màn hình danh sách để biết
    // phiếu này ĐÃ có hoàn trả nào chưa mà không tải toàn bộ dòng.
    public function returnItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            WarehouseStockReturnItem::class,
            WarehouseStockOutItem::class,
            'warehouse_stock_out_id',
            'warehouse_stock_out_item_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reasonsSummary(): string
    {
        return $this->items
            ->pluck('reason')
            ->filter()
            ->unique()
            ->map(fn (string $reason) => self::REASONS[$reason] ?? $reason)
            ->implode(', ');
    }
}
