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

class WarehouseStockIn extends Model
{
    use BelongsToPartner;
    use BelongsToBranch;
    use LogsAuditTrail;

    protected $fillable = [
        'partner_id',
        'branch_id',
        'code',
        'received_at',
        'total_amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'received_at'  => 'datetime',
        // 'float' thay vì 'decimal:2' — decimal cast luôn trả về CHUỖI đủ 2 số lẻ ("30000.00"),
        // khiến ô nhập/hiển thị bị dính đuôi ",00" dù giá trị là số nguyên. float trả PHP number
        // thật, tự bỏ số 0 thừa khi convert sang chuỗi ("30000"), vẫn giữ đúng 2 số lẻ khi cần
        // ("1.5"). DB vẫn lưu decimal(15,2), không đổi độ chính xác lưu trữ.
        'total_amount' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (WarehouseStockIn $stockIn) {
            if (empty($stockIn->code)) {
                $stockIn->code = static::generateCode();
            }

            if (empty($stockIn->created_by) && auth()->check()) {
                $stockIn->created_by = auth()->id();
            }

            // "Ngày nhập" chốt CỨNG theo đúng thời điểm lưu phiếu — không cho client (cả form
            // Filament lẫn API) tự truyền ngày khác, tránh khai man thời điểm nhập hàng thực tế.
            $stockIn->received_at = now();
        });

        // Xóa từng dòng qua Eloquent (thay vì để FK cascadeOnDelete xóa thẳng ở tầng DB) để
        // WarehouseStockInItem::deleted() kịp hoàn tác tồn kho đã cộng khi tạo phiếu.
        static::deleting(function (WarehouseStockIn $stockIn) {
            $stockIn->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        // "code" là unique TOÀN HỆ THỐNG (không tách theo đối tác) nhưng BelongsToPartner tự lọc
        // query theo đối tác đang đăng nhập — nếu kiểm tra trùng qua static::where() bình thường,
        // 2 đối tác khác nhau có thể cùng sinh ra "PN000001" (mỗi bên chỉ thấy phiếu của mình) rồi
        // vỡ ràng buộc unique khi lưu. Phải bỏ qua global scope 'partner' khi kiểm tra trùng.
        $number = static::withoutGlobalScope('partner')->count() + 1;

        do {
            $code = 'PN' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (static::withoutGlobalScope('partner')->where('code', $code)->exists());

        return $code;
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockInItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recalculateTotal(): void
    {
        $this->updateQuietly([
            'total_amount' => $this->items()->sum('amount'),
        ]);
    }
}
