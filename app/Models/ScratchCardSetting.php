<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình thẻ cào toàn hệ thống (1 dòng duy nhất) — Super Admin chỉnh qua /api/admin/games/scratch-card/settings.
class ScratchCardSetting extends Model
{
    protected $fillable = ['is_enabled', 'expire_days', 'min_stay_days', 'min_order_amount', 'rating_bonus_enabled'];

    protected $casts = [
        'is_enabled'           => 'boolean',
        'rating_bonus_enabled' => 'boolean',
        'expire_days'          => 'integer',
        'min_stay_days'        => 'integer',
        'min_order_amount'     => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }
}
