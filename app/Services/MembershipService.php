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
        $this->assignTierCoupons($customer, $tier);
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
        $this->assignTierCoupons($customer, $targetTier);
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
     * Gán hạng thủ công từ admin: cập nhật tier, ghi log, phát coupon.
     * Trả về coupon vừa tạo (hoặc null nếu tier không cấu hình coupon).
     */
    public function assignManually(Customer $customer, int $toTierId, ?int $fromTierId = null): ?Coupon
    {
        $customer->update(['membership_tier_id' => $toTierId]);

        CustomerMembershipLog::create([
            'customer_id'        => $customer->id,
            'from_tier_id'       => $fromTierId,
            'to_tier_id'         => $toTierId,
            'reason'             => 'manual',
            'spending_at_change' => $customer->total_spending ?? 0,
        ]);

        $tier = MembershipTier::find($toTierId);

        if (! $tier) {
            return null;
        }

        $this->assignTierCoupons($customer, $tier);

        return $this->issueTierCoupon($customer, $tier);
    }

    /**
     * Phát coupon có sẵn đã gắn vào hạng cho toàn bộ customer đang giữ hạng đó.
     * Gọi khi admin gắn/gỡ coupon khỏi hạng trong Filament.
     */
    public function distributeTierCoupons(MembershipTier $tier): void
    {
        $couponIds = $tier->coupons()->pluck('coupons.id')->all();

        if (empty($couponIds)) {
            return;
        }

        Customer::where('membership_tier_id', $tier->id)
            ->get()
            ->each(fn (Customer $customer) => $customer->coupons()->syncWithoutDetaching($couponIds));
    }

    /**
     * Gán các coupon có sẵn của hạng cho một customer (khi vừa lên/đổi hạng).
     */
    private function assignTierCoupons(Customer $customer, MembershipTier $tier): void
    {
        $couponIds = $tier->coupons()->pluck('coupons.id')->all();

        if (! empty($couponIds)) {
            $customer->coupons()->syncWithoutDetaching($couponIds);
        }
    }

    /**
     * Tạo coupon cá nhân cho customer dựa theo cấu hình của tier.
     */
    private function issueTierCoupon(Customer $customer, MembershipTier $tier): ?Coupon
    {
        if (! $tier->welcome_coupon_value || $tier->welcome_coupon_value <= 0) {
            return null;
        }

        $prefix = strtoupper($tier->welcome_coupon_prefix ?: Str::slug($tier->name, ''));
        $code   = $prefix . strtoupper(Str::random(6));

        return Coupon::create([
            'code'          => $code,
            'name'          => 'Ưu đãi hạng ' . $tier->name . ' — ' . $customer->fullname,
            'description'   => 'Coupon tự động cấp khi đạt hạng ' . $tier->name,
            'type'          => $tier->welcome_coupon_type,
            'value'         => $tier->welcome_coupon_value,
            'apply_type'    => 'all_rooms',
            'usage_limit'   => $tier->welcome_coupon_usage_limit,
            'used_count'    => 0,
            'start_at'      => now(),
            'end_at'        => $tier->welcome_coupon_days ? now()->addDays($tier->welcome_coupon_days) : null,
            'is_active'     => true,
            'customer_id'   => $customer->id,
        ]);
    }
}
