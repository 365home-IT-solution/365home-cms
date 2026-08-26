<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Promotion\App\Models\Coupon;

class MembershipTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'image',
        'sort_order',
        'min_spending',
        'welcome_coupon_prefix',
        'welcome_coupon_type',
        'welcome_coupon_value',
        'welcome_coupon_days',
        'welcome_coupon_usage_limit',
        'is_active',
        'auto_issue_enabled',
        'auto_issue_interval_days',
        'auto_issue_coupon_type',
        'auto_issue_coupon_value',
        'auto_issue_coupon_value_max',
        'auto_issue_coupon_days',
        'auto_issue_coupon_usage_limit',
        'auto_issue_notify_title',
        'auto_issue_notify_body',
        'checkin_reminder_enabled',
        'checkin_reminder_times',
    ];

    protected $casts = [
        'min_spending'                  => 'decimal:2',
        'welcome_coupon_value'          => 'decimal:2',
        'welcome_coupon_days'           => 'integer',
        'welcome_coupon_usage_limit'    => 'integer',
        'is_active'                     => 'boolean',
        'auto_issue_enabled'            => 'boolean',
        'auto_issue_interval_days'      => 'integer',
        'auto_issue_coupon_value'       => 'decimal:2',
        'auto_issue_coupon_value_max'   => 'decimal:2',
        'auto_issue_coupon_days'        => 'integer',
        'auto_issue_coupon_usage_limit' => 'integer',
        'checkin_reminder_enabled'      => 'boolean',
        'checkin_reminder_times'        => 'array',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'membership_tier_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CustomerMembershipLog::class, 'to_tier_id');
    }

    /**
     * Coupon có sẵn được gắn vào hạng — khi gắn sẽ phát cho mọi customer đang giữ hạng này.
     */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'membership_tier_coupon', 'membership_tier_id', 'coupon_id')
            ->withPivot('source')
            ->withTimestamps();
    }

    public static function findForSpending(float $spending): ?self
    {
        return self::where('is_active', true)
            ->where('min_spending', '<=', $spending)
            ->orderByDesc('min_spending')
            ->first();
    }
}
