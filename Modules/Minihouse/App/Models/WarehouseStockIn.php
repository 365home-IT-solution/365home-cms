<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Phiếu nhập kho — mirror Modules\Warehouse\App\Models\WarehouseStockIn (Home).
class WarehouseStockIn extends Model
{
    use ScopedToActiveBuildingId, LogsMinihouseActivity;

    protected $table = 'minihouse_warehouse_stock_ins';

    protected $fillable = ['building_id', 'code', 'received_at', 'total_amount', 'note', 'created_by'];

    protected $casts = [
        'received_at'  => 'datetime',
        'total_amount' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (WarehouseStockIn $stockIn) {
            if (blank($stockIn->code)) {
                $stockIn->code = self::generateCode();
            }

            if (blank($stockIn->created_by)) {
                $stockIn->created_by = auth()->id();
            }

            // Luôn CHỐT thời điểm nhập kho THẬT = lúc lưu phiếu — không cho client (Filament/API) tự
            // truyền/backdate, tránh khai khống thời điểm nhận hàng.
            $stockIn->received_at = now();
        });

        // Xoá TỪNG DÒNG qua Eloquent (không cascade DB thuần) — để WarehouseStockInItem::deleted()
        // tự hoàn tác đúng số lượng đã cộng vào vật tư.
        static::deleting(function (WarehouseStockIn $stockIn) {
            $stockIn->items->each->delete();
        });
    }

    // "PN" (Phiếu Nhập) + 6 số — duy nhất TOÀN HỆ THỐNG (không theo từng Toà nhà) nên phải tự bỏ qua
    // scope 'activeBuilding' lúc dò số tiếp theo, nếu không 2 Toà nhà có thể tự sinh trùng mã.
    public static function generateCode(): string
    {
        $last = static::withoutGlobalScope('activeBuilding')
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->value('id');

        return 'PN' . str_pad((string) (((int) $last) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'building_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockInItem::class, 'warehouse_stock_in_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalculateTotal(): void
    {
        $this->updateQuietly(['total_amount' => $this->items()->sum('amount')]);
    }
}
