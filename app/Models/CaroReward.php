<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\App\Models\Coupon;

// Một lần khách đạt mốc thưởng và được trao voucher caro.
class CaroReward extends Model
{
    protected $fillable = ['customer_id', 'match_id', 'prize_id', 'prize_label', 'coupon_id', 'milestone_wins', 'expiry_reminded_at'];

    protected $casts = [
        'expiry_reminded_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withoutGlobalScopes();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
