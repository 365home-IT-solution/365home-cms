<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;

class ManualLockPassword extends Model
{
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
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
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
            ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
            ->latest()
            ->first();
    }
}
