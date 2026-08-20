<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Employee\Entities\Employee;
use Modules\Product\App\Models\Product;

class WarehouseStockOut extends Model
{
    use BelongsToPartner;

    public const REASONS = [
        'housekeeping' => 'Buồng phòng / Dọn phòng',
        'guest_usage'  => 'Sử dụng cho khách/phòng',
        'damaged'      => 'Hao hụt / Hư hỏng',
        'other'        => 'Khác',
    ];

    protected $fillable = [
        'partner_id',
        'code',
        'employee_id',
        'product_id',
        'issued_to',
        'issued_at',
        'note',
        'created_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (WarehouseStockOut $stockOut) {
            if (empty($stockOut->code)) {
                $stockOut->code = static::generateCode();
            }

            if (empty($stockOut->created_by) && auth()->check()) {
                $stockOut->created_by = auth()->id();
            }

            // "Ngày xuất" chốt CỨNG theo đúng thời điểm lưu phiếu — không cho client (cả form
            // Filament lẫn API) tự truyền ngày khác, tránh khai man thời điểm xuất hàng thực tế.
            // Đồng nhất với WarehouseStockIn::creating().
            $stockOut->issued_at = now();
        });

        // Xóa từng dòng qua Eloquent (thay vì để FK cascadeOnDelete xóa thẳng ở tầng DB) để
        // WarehouseStockOutItem::deleted() kịp hoàn tác tồn kho đã trừ khi tạo phiếu.
        static::deleting(function (WarehouseStockOut $stockOut) {
            $stockOut->items->each->delete();
        });
    }

    public static function generateCode(): string
    {
        // "code" là unique TOÀN HỆ THỐNG (không tách theo đối tác) nhưng BelongsToPartner tự lọc
        // query theo đối tác đang đăng nhập — nếu kiểm tra trùng qua static::where() bình thường,
        // 2 đối tác khác nhau có thể cùng sinh ra "PX000001" (mỗi bên chỉ thấy phiếu của mình) rồi
        // vỡ ràng buộc unique khi lưu. Phải bỏ qua global scope 'partner' khi kiểm tra trùng.
        $number = static::withoutGlobalScope('partner')->count() + 1;

        do {
            $code = 'PX' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (static::withoutGlobalScope('partner')->where('code', $code)->exists());

        return $code;
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseStockOutItem::class);
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

    // "Lý do xuất" giờ thuộc về TỪNG DÒNG (warehouse_stock_out_items.reason) — trong cùng 1 phiếu
    // các vật tư có thể tiêu hao vì lý do khác nhau (vừa hao hụt vừa housekeeping bình thường
    // trong cùng 1 lượt dọn phòng), gộp chung 1 lý do cho cả phiếu là sai bản chất. Dùng hàm này ở
    // bảng danh sách để tóm tắt nhanh các lý do khác nhau đang có trong phiếu.
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
