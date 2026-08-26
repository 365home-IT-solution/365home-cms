<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Promotion\App\Models\Coupon;

class CustomerCheckinCycle extends Model
{
    protected $fillable = [
        'customer_id',
        'membership_tier_id',
        'days_required',
        'cycle_start_date',
        'days_checked',
        'completed_at',
        'coupon_id',
    ];

    protected $casts = [
        'cycle_start_date' => 'date',
        'days_required'    => 'integer',
        'days_checked'     => 'integer',
        'completed_at'     => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function membershipTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'membership_tier_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(CustomerCheckinDay::class, 'customer_checkin_cycle_id');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
