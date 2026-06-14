<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'sort_order',
        'min_spending',
        'welcome_coupon_prefix',
        'welcome_coupon_type',
        'welcome_coupon_value',
        'welcome_coupon_days',
        'is_active',
    ];

    protected $casts = [
        'min_spending'          => 'decimal:2',
        'welcome_coupon_value'  => 'decimal:2',
        'welcome_coupon_days'   => 'integer',
        'is_active'             => 'boolean',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'membership_tier_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CustomerMembershipLog::class, 'to_tier_id');
    }

    public static function findForSpending(float $spending): ?self
    {
        return self::where('is_active', true)
            ->where('min_spending', '<=', $spending)
            ->orderByDesc('min_spending')
            ->first();
    }
}
