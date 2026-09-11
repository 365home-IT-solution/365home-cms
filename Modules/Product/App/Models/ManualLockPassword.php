<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;

class ManualLockPassword extends Model
{
    use LogsAuditTrail;

    protected $fillable = [
        'name',
        'gate_password',
        'room_password',
        'category_id',
        'notes',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'is_active'   => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'manual_lock_password_product',
            'manual_lock_password_id',
            'product_id'
        );
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now());
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function isExpiringSoon(): bool
    {
        if ($this->valid_until === null || $this->isExpired()) {
            return false;
        }

        // "Sắp hết hạn" chỉ khi hết hạn trong ngày hôm nay
        return $this->valid_until->isToday();
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_active) {
            return 'Ngừng hoạt động';
        }

        if ($this->isExpired()) {
            return 'Đã hết hạn';
        }

        if ($this->isExpiringSoon()) {
            return 'Sắp hết hạn';
        }

        return 'Đang hoạt động';
    }

    public function getStatusColorAttribute(): string
    {
        if (! $this->is_active) {
            return 'gray';
        }

        if ($this->isExpired()) {
            return 'danger';
        }

        if ($this->isExpiringSoon()) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * Mark this record as inactive (expired).
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Tìm bộ mật khẩu đang hoạt động cho một phòng tại một thời điểm mốc cụ thể.
     * Ưu tiên: trùng khoảng ngày → mới nhất theo valid_from → fallback bất kỳ active.
     *
     * Khớp theo 1 trong 2 cách (xem scopeMatchesProduct()):
     *  - Gán trực tiếp cho phòng này (quan hệ products()) — dù bản ghi thuộc chi nhánh nào.
     *  - Gán cho ĐÚNG chi nhánh (category_id) của phòng này VÀ không chọn phòng cụ thể nào
     *    (form Filament: mục "Phòng áp dụng" để trống) — nghĩa là áp dụng cho CẢ chi nhánh.
     *    Trước đây chỉ khớp theo phòng cụ thể, nên bộ mật khẩu gán theo chi nhánh (không tick
     *    phòng nào) không bao giờ được tìm thấy — chi nhánh có mã cổng thủ công vẫn không hiển
     *    thị được, lại rơi về nhánh TTLock/null phía buildLockInfo().
     *
     * Lưu ý: $referenceDate phải là thời điểm đơn được "chốt" (khách thanh toán/đặt cọc),
     * KHÔNG phải checkin_date của đơn và KHÔNG phải now() tại thời điểm xem lại — nếu không,
     * mật khẩu hiển thị cho khách sẽ đổi qua ngày khác mỗi khi họ xem lại vé sau đó, hoặc lộ
     * mật khẩu của một ngày còn chưa tới hiệu lực. Mật khẩu được gán 1 lần tại thời điểm chốt
     * đơn và giữ nguyên cho tới khi tự hết hạn theo valid_until của chính bản ghi đó.
     */
    public static function getForProductAndDate(
        Product $product,
        ?\Carbon\Carbon $referenceDate = null
    ): ?self {
        $date = $referenceDate ?? now();

        // 1. Tìm bản ghi có valid_from <= checkin <= valid_until (ưu tiên)
        $match = static::active()
            ->where(fn ($q) => static::scopeMatchesProduct($q, $product))
            ->where(function ($q) use ($date) {
                $q->where(function ($inner) use ($date) {
                    $inner->where('valid_from', '<=', $date)
                          ->where('valid_until', '>=', $date);
                })->orWhere(function ($inner) {
                    $inner->whereNull('valid_from')->whereNull('valid_until');
                });
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($match) {
            return $match;
        }

        // 2. Fallback: bộ mật khẩu active mới nhất cho phòng đó
        return static::active()
            ->where(fn ($q) => static::scopeMatchesProduct($q, $product))
            ->latest()
            ->first();
    }

    /**
     * Điều kiện khớp phòng dùng chung cho cả 2 bước tìm ở trên — xem giải thích đầy đủ ở
     * docblock getForProductAndDate().
     */
    private static function scopeMatchesProduct(Builder $query, Product $product): Builder
    {
        $branchCategoryIds = $product->categories()
            ->where('category_type', 'product')
            ->pluck('categories.id')
            ->all();

        return $query
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
            ->orWhere(function ($branchScope) use ($branchCategoryIds) {
                $branchScope->whereIn('category_id', $branchCategoryIds)
                    ->doesntHave('products');
            });
    }
}
