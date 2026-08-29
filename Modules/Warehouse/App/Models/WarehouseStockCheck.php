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

class WarehouseStockCheck extends Model
{
    use BelongsToPartner;
    use BelongsToBranch;
    use LogsAuditTrail;

    // Xem giải thích đầy đủ ở migration 2026_08_15_000014 — "bàn giao ca" là bước ca sau đếm lại
    // đúng các vật tư trong phiếu kiểm kê này để xác minh, KHÔNG tự động điều chỉnh tồn kho.
    public const HANDOVER_CONFIRMED   = 'confirmed';
    public const HANDOVER_DISCREPANCY = 'discrepancy';

    public const HANDOVER_LABELS = [
        self::HANDOVER_CONFIRMED   => 'Đã xác nhận khớp',
        self::HANDOVER_DISCREPANCY => 'Có lệch bàn giao',
    ];

    protected $fillable = [
        'partner_id',
        'branch_id',
        'code',
        'checked_at',
        'note',
        'created_by',
        'handover_status',
        'handover_confirmed_by',
        'handover_confirmed_at',
        'handover_note',
    ];

    protected $casts = [
        'checked_at'             => 'datetime',
        'handover_confirmed_at'  => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (WarehouseStockCheck $stockCheck) {
            if (empty($stockCheck->code)) {
                $stockCheck->code = static::generateCode();
            }

            if (empty($stockCheck->created_by) && auth()->check()) {
                $stockCheck->created_by = auth()->id();
            }
        });

        // Xóa từng dòng qua Eloquent (thay vì để FK cascadeOnDelete xóa thẳng ở tầng DB) để
        // WarehouseStockCheckItem::deleted() kịp hoàn tác tồn kho đã điều chỉnh khi tạo phiếu.
        static::deleting(function (WarehouseStockCheck $stockCheck) {
            $stockCheck->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        // "code" là unique TOÀN HỆ THỐNG (không tách theo đối tác) nhưng BelongsToPartner tự lọc
        // query theo đối tác đang đăng nhập — nếu kiểm tra trùng qua static::where() bình thường,
        // 2 đối tác khác nhau có thể cùng sinh ra "PK000001" (mỗi bên chỉ thấy phiếu của mình) rồi
        // vỡ ràng buộc unique khi lưu. Phải bỏ qua global scope 'partner' khi kiểm tra trùng.
        $number = static::withoutGlobalScope('partner')->count() + 1;

        do {
            $code = 'PK' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (static::withoutGlobalScope('partner')->where('code', $code)->exists());

        return $code;
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockCheckItem::class);
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
