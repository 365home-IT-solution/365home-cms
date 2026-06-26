<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMembershipLog extends Model
{
    protected $fillable = [
        'customer_id',
        'from_tier_id',
        'to_tier_id',
        'reason',
        'spending_at_change',
    ];

    protected $casts = [
        'spending_at_change' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function fromTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'from_tier_id');
    }

    public function toTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'to_tier_id');
    }
}
