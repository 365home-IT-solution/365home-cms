<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Employee\Entities\Employee;
use Modules\Product\App\Models\Product;

class WarehouseStockReturn extends Model
{
    use BelongsToPartner;
    use BelongsToBranch;
    use LogsAuditTrail;

    protected $fillable = [
        'partner_id',
        'branch_id',
        'code',
        'employee_id',
        'product_id',
        'returned_by',
        'returned_at',
        'note',
        'created_by',
    ];

    protected $casts = [
        'returned_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (WarehouseStockReturn $stockReturn) {
            if (empty($stockReturn->code)) {
                $stockReturn->code = static::generateCode();
            }

            if (empty($stockReturn->created_by) && auth()->check()) {
                $stockReturn->created_by = auth()->id();
            }

            // "Ngày hoàn" chốt CỨNG theo thời điểm lưu phiếu, đồng nhất với WarehouseStockOut::creating().
            $stockReturn->returned_at = now();
        });

        // Xóa từng dòng qua Eloquent để WarehouseStockReturnItem::deleted() kịp hoàn tác tồn kho đã
        // cộng khi tạo phiếu — cùng lý do ở WarehouseStockOut::deleting().
        static::deleting(function (WarehouseStockReturn $stockReturn) {
            $stockReturn->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        // "PH" = Phiếu Hoàn — unique toàn hệ thống, bỏ qua global scope 'partner' khi kiểm tra
        // trùng, cùng lý do ở WarehouseStockOut::generateCode().
        $number = static::withoutGlobalScope('partner')->count() + 1;

        do {
            $code = 'PH' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (static::withoutGlobalScope('partner')->where('code', $code)->exists());

        return $code;
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockReturnItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
