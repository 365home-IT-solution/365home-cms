<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Phiếu hoàn trả kho — mirror Modules\Warehouse\App\Models\WarehouseStockReturn (Home). Tạo từ dòng
// đã xuất (giới hạn không vượt số đã xuất - đã hoàn trước đó) HOẶC tạo tay cho vật tư dư tìm thấy
// (không tham chiếu phiếu xuất — không giới hạn số lượng).
class WarehouseStockReturn extends Model
{
    use ScopedToActiveBuildingId, LogsMinihouseActivity;

    protected $table = 'minihouse_warehouse_stock_returns';

    protected $fillable = ['building_id', 'code', 'room_id', 'returned_by', 'returned_at', 'note', 'created_by'];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WarehouseStockReturn $stockReturn) {
            if (blank($stockReturn->code)) {
                $stockReturn->code = self::generateCode();
            }

            if (blank($stockReturn->created_by)) {
                $stockReturn->created_by = auth()->id();
            }

            $stockReturn->returned_at = now();
        });

        static::deleting(function (WarehouseStockReturn $stockReturn) {
            $stockReturn->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        $last = static::withoutGlobalScope('activeBuilding')
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->value('id');

        return 'PH' . str_pad((string) (((int) $last) + 1), 6, '0', STR_PAD_LEFT);
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
        return $this->hasMany(WarehouseStockReturnItem::class, 'warehouse_stock_return_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
