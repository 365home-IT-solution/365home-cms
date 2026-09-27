<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;


// tính năng "xác nhận bàn giao ca" của bản Home — MiniHouse không có nghiệp vụ ca trực, lược bỏ để
// đơn giản hoá.
class WarehouseStockCheck extends Model
{
    use ScopedToActiveBuildingId, LogsMinihouseActivity;

    public const HANDOVER_CONFIRMED = 'confirmed';

    public const HANDOVER_DISCREPANCY = 'discrepancy';

    public const HANDOVER_LABELS = [
        self::HANDOVER_CONFIRMED   => 'Đã bàn giao — khớp',
        self::HANDOVER_DISCREPANCY => 'Có lệch bàn giao',
    ];

    protected $table = 'minihouse_warehouse_stock_checks';

    protected $fillable = [
        'building_id', 'code', 'checked_at', 'note', 'created_by',
        'handover_status', 'handover_confirmed_by', 'handover_confirmed_at', 'handover_note',
    ];

    protected $casts = [
        'checked_at'            => 'datetime',
        'handover_confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WarehouseStockCheck $stockCheck) {
            if (blank($stockCheck->code)) {
                $stockCheck->code = self::generateCode();
            }

            if (blank($stockCheck->created_by)) {
                $stockCheck->created_by = auth()->id();
            }
        });

        static::deleting(function (WarehouseStockCheck $stockCheck) {
            $stockCheck->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        $last = static::withoutGlobalScope('activeBuilding')
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->value('id');

        return 'PK' . str_pad((string) (((int) $last) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'building_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockCheckItem::class, 'warehouse_stock_check_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function handoverConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handover_confirmed_by');
    }
}
