<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình giới thiệu bạn bè toàn hệ thống (1 dòng duy nhất) — Super Admin chỉnh qua /api/admin/games/referral/settings.
class ReferralSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days', 'monthly_limit', 'apply_days', 'share_url_base',
    ];

    protected $casts = [
        'is_enabled'      => 'boolean',
        'coupon_value'    => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount'    => 'decimal:2',
        'validity_days'   => 'integer',
        'monthly_limit'   => 'integer',
        'apply_days'      => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }
}
