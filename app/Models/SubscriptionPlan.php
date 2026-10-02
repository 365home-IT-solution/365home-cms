<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Gói dịch vụ (chỉ MiniHouse): giá theo tháng, đối tác mua theo kỳ 1/3/6/9/12 tháng; mở toàn bộ chức năng quản trị.
class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'partner_type', 'price_vnd', 'period_months', 'trial_months', 'period_discounts', 'support_info',
        'is_default', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price_vnd'     => 'integer',
        'period_months' => 'integer',
        'trial_months'  => 'integer',
        'support_info'  => 'array',
        'period_discounts' => 'array',
        'is_default'    => 'boolean',
        'is_active'     => 'boolean',
    ];

    protected static function booted(): void
    {
        // Mỗi loại đối tác chỉ có 1 gói mặc định.
        static::saved(function (self $plan) {
            if ($plan->is_default) {
                static::query()->where('id', '!=', $plan->id)->where('partner_type', $plan->partner_type)->update(['is_default' => false]);
            }
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PartnerSubscription::class, 'plan_id');
    }

    public function scopeForPartnerType(Builder $query, ?string $type): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('partner_type')->orWhere('partner_type', $type));
    }

    /** % giảm giá của kỳ mua $periods (0 nếu chưa cấu hình). */
    public function discountPercent(int $periods): int
    {
        return max(0, min(100, (int) (((array) $this->period_discounts)[$periods] ?? 0)));
    }

    /** Số tiền phải trả khi mua $periods kỳ = giá/tháng × số kỳ, đã trừ % ưu đãi của kỳ đó. */
    public function amountFor(int $periods): int
    {
        $base = $this->price_vnd * $periods;

        return (int) round($base * (100 - $this->discountPercent($periods)) / 100);
    }

    /** Các kỳ mua và số tiền tương ứng theo config subscription.period_options. */
    public function periodOptions(): array
    {
        return collect(config('subscription.period_options'))
            ->map(fn (int $n) => [
                'months'           => $n * $this->period_months,
                'periods'          => $n,
                'original_vnd'     => $this->price_vnd * $n,
                'discount_percent' => $this->discountPercent($n),
                'amount_vnd'       => $this->amountFor($n),
            ])
            ->values()
            ->all();
    }

    public function toApi(): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'description'   => $this->description,
            'partner_type'  => $this->partner_type,
            'price_vnd'     => $this->price_vnd,
            'period_months' => $this->period_months,
            'trial_months'  => $this->trial_months,
            'support_info'  => (array) $this->support_info,
            // Các kỳ được mua (số kỳ gửi lên API checkout = periods) và số tiền phải trả
            'periods'       => $this->periodOptions(),
        ];
    }
}
