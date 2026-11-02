<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Category\Traits\Categorizable;

// Một voucher trong bộ giải caro. Chi nhánh gán qua categories() được chép sang voucher khi trao (để trống = mọi chi nhánh).
// Giải is_fallback không giới hạn lượt — hết ngân sách các giải khác thì rơi về giải này.
class CaroPrize extends Model
{
    use Categorizable;

    protected $fillable = [
        'label', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days', 'is_exclusive',
        'weight', 'daily_limit', 'total_limit', 'awarded_count', 'is_fallback', 'is_active',
    ];

    protected $casts = [
        'is_exclusive'    => 'boolean',
        'is_fallback'     => 'boolean',
        'is_active'       => 'boolean',
        'coupon_value'    => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount'    => 'decimal:2',
    ];
}
