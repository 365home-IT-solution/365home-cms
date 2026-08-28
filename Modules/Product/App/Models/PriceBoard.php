<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Promotion\App\Models\Promotion;
use Modules\Promotion\App\Models\Coupon;

class PriceBoard extends Model
{
    use HasFactory;

    public const MODE_OVERRIDE   = 'override';
    public const MODE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'name',
        'note',
        'start_date',
        'end_date',
        'is_default',
        'is_active',
        'pricing_mode',
        'adjustment_type',
        'adjustment_value',
    ];

    protected $casts = [
        'start_date'       => 'date',
        'end_date'         => 'date',
        'is_default'       => 'boolean',
        'is_active'        => 'boolean',
        'adjustment_value' => 'decimal:2',
    ];

    public function isAdjustment(): bool
    {
        return $this->pricing_mode === self::MODE_ADJUSTMENT;
    }

    public function items()
    {
        return $this->hasMany(PriceBoardItem::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'price_board_items', 'price_board_id', 'product_id')
            ->withPivot([
                'id', 'price', 'price_unit', 'full_booking_discount', 'bulk_discount_rules',
                'room_config', 'deposit_1_night', 'deposit_multi_night', 'deposit_min_nights',
                'default_checkin', 'default_checkout',
            ]);
    }

    public function promotions()
    {
        return $this->hasMany(Promotion::class);
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    /** Bảng đang trong khoảng hiệu lực tại ngày $date (mặc định hôm nay). */
    public function coversDate(?\Carbon\Carbon $date = null): bool
    {
        $date = $date ?? now()->startOfDay();

        if ($this->start_date && $date->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }

        return true;
    }
}
