<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembershipLog;
use App\Models\MembershipTier;
use Illuminate\Support\Str;
use Modules\Promotion\App\Models\Coupon;

class MembershipService
{
    /**
     * Gán tier thấp nhất (min_spending = 0) và phát coupon chào mừng.
     * Gọi khi customer vừa đăng ký.
     */
    public function assignWelcomeTier(Customer $customer): void
    {
        if ($customer->membership_tier_id) {
            return;
        }

        $tier = MembershipTier::where('is_active', true)
            ->where('min_spending', 0)
            ->orderBy('sort_order')
            ->first();

        if (! $tier) {
            return;
        }

        $customer->update([
            'membership_tier_id'     => $tier->id,
            'welcome_coupon_sent_at' => now(),
        ]);

        CustomerMembershipLog::create([
            'customer_id'       => $customer->id,
            'from_tier_id'      => null,
            'to_tier_id'        => $tier->id,
            'reason'            => 'registration',
            'spending_at_change'=> 0,
        ]);

        $this->issueTierCoupon($customer, $tier);
    }

    /**
     * Kiểm tra và nâng hạng dựa trên tổng chi tiêu.
     * Gọi sau mỗi đơn hàng được thanh toán.
     */
    public function recalculateTier(Customer $customer): void
    {
        $spending    = (float) $customer->total_spending;
        $targetTier  = MembershipTier::findForSpending($spending);

        if (! $targetTier || $targetTier->id === $customer->membership_tier_id) {
            return;
        }

        $fromTierId = $customer->membership_tier_id;

        $customer->update(['membership_tier_id' => $targetTier->id]);

        CustomerMembershipLog::create([
            'customer_id'        => $customer->id,
            'from_tier_id'       => $fromTierId,
            'to_tier_id'         => $targetTier->id,
            'reason'             => 'spending_upgrade',
            'spending_at_change' => $spending,
        ]);

        $this->issueTierCoupon($customer, $targetTier);
    }

    /**
     * Cộng dồn tổng chi tiêu rồi tự động tính lại tier.
     */
    public function addSpending(Customer $customer, float $amount): void
    {
        $customer->increment('total_spending', $amount);
        $customer->refresh();
        $this->recalculateTier($customer);
    }

    /**
     * Tạo coupon cá nhân cho customer dựa theo cấu hình của tier.
     */
    private function issueTierCoupon(Customer $customer, MembershipTier $tier): void
    {
        if (! $tier->welcome_coupon_value || $tier->welcome_coupon_value <= 0) {
            return;
        }

        $prefix = strtoupper($tier->welcome_coupon_prefix ?: Str::slug($tier->name, ''));
        $code   = $prefix . strtoupper(Str::random(6));

        Coupon::create([
            'code'          => $code,
            'name'          => 'Ưu đãi hạng ' . $tier->name . ' — ' . $customer->fullname,
            'description'   => 'Coupon tự động cấp khi đạt hạng ' . $tier->name,
            'type'          => $tier->welcome_coupon_type,
            'value'         => $tier->welcome_coupon_value,
            'apply_type'    => 'all_rooms',
            'usage_limit'   => 1,
            'used_count'    => 0,
            'start_at'      => now(),
            'end_at'        => now()->addDays($tier->welcome_coupon_days),
            'is_active'     => true,
            'customer_id'   => $customer->id,
        ]);
    }
}
