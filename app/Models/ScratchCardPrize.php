<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Một giải trong bộ giải thẻ cào. Giải is_fallback không giới hạn lượt — hết ngân sách các giải khác thì rơi về giải này.
class ScratchCardPrize extends Model
{
    public const TIERS = ['normal', 'rare', 'jackpot'];

    protected $fillable = [
        'label', 'type', 'tier', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days',
        'weight', 'daily_limit', 'total_limit', 'awarded_count', 'is_fallback', 'is_active',
    ];

    protected $casts = [
        'is_fallback'     => 'boolean',
        'is_active'       => 'boolean',
        'coupon_value'    => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount'    => 'decimal:2',
    ];
}
