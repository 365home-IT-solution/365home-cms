<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\App\Models\Coupon;

// Một cặp giới thiệu: người giới thiệu (referrer) ↔ người được giới thiệu (referee, mỗi tài khoản đúng một dòng).
class Referral extends Model
{
    public const STATUS_JOINED   = 'joined';
    public const STATUS_REWARDED = 'rewarded';
    public const STATUS_INVALID  = 'invalid';

    protected $fillable = [
        'referrer_id', 'referee_id', 'code', 'device_id', 'status', 'reason', 'welcome_coupon_id', 'first_order_id', 'scratch_card_id', 'rewarded_at',
    ];

    protected $casts = ['rewarded_at' => 'datetime'];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referee_id');
    }

    public function welcomeCoupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'welcome_coupon_id')->withoutGlobalScopes();
    }
}
