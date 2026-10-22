<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Promotion\App\Models\Coupon;

// Lần đổi thưởng của khách ở một chiến dịch (tối đa 1 / khách / chiến dịch).
class CollectionRedemption extends Model
{
    protected $fillable = ['campaign_id', 'customer_id', 'coupon_id', 'redeemed_at'];

    protected $casts = ['redeemed_at' => 'datetime'];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withoutGlobalScopes();
    }
}
