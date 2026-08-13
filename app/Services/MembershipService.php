<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembershipLog;
use App\Models\MembershipTier;
use App\Models\User;
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
        $templates = $tier->coupons()->get();

        if ($templates->isEmpty()) {
            return;
        }

        Customer::where('membership_tier_id', $tier->id)
            ->get()
            ->each(function (Customer $customer) use ($templates) {
                foreach ($templates as $template) {
                    $this->grantTemplateCoupon($customer, $template);
                }
            });
    }

    /**
     * Gán các coupon có sẵn của hạng cho một customer (khi vừa lên/đổi hạng).
     */
    private function assignTierCoupons(Customer $customer, MembershipTier $tier): void
    {
        foreach ($tier->coupons()->get() as $template) {
            $this->grantTemplateCoupon($customer, $template);
        }
    }

    /**
     * Cấp 1 coupon MẪU của hạng cho 1 customer.
     * - Coupon mẫu KHÔNG có validity_days: giữ hành vi cũ — gắn thẳng coupon DÙNG CHUNG (hạn cố
     *   định theo start_at/end_at của chính coupon mẫu) qua bảng coupon_customers.
     * - Coupon mẫu CÓ validity_days: nhân bản 1 coupon RIÊNG cho customer này (customer_id + hạn =
     *   thời điểm cấp + validity_days), hỗ trợ nhiều voucher/hạng mà mỗi voucher hạn tính theo
     *   ngày từng khách lên hạng thay vì 1 ngày cố định dùng chung. Kiểm tra template_coupon_id
     *   trước khi tạo để tránh cấp trùng nếu hàm này được gọi lại nhiều lần cho cùng 1 lượt lên hạng
     *   (vd distributeTierCoupons() chạy lại khi admin sửa hạng).
     */
    private function grantTemplateCoupon(Customer $customer, Coupon $template): void
    {
        if (! $template->validity_days) {
            $customer->coupons()->syncWithoutDetaching([$template->id]);

            return;
        }

        $alreadyIssued = Coupon::where('customer_id', $customer->id)
            ->where('template_coupon_id', $template->id)
            ->exists();

        if ($alreadyIssued) {
            return;
        }

        $prefix = strtoupper(Str::slug($template->code, ''));

        Coupon::create([
            'partner_id'          => $template->partner_id,
            'code'                => Str::limit($prefix, 10, '') . strtoupper(Str::random(6)),
            'name'                => $template->name,
            'description'         => $template->description,
            'type'                => $template->type,
            'value'               => $template->value,
            'apply_type'          => $template->apply_type,
            'room_id'             => $template->room_id,
            'min_order_value'     => $template->min_order_value,
            'max_discount'        => $template->max_discount,
            'usage_limit'         => $template->usage_limit,
            'used_count'          => 0,
            'start_at'            => now(),
            'end_at'              => now()->addDays($template->validity_days),
            'is_active'           => true,
            'customer_id'         => $customer->id,
            'template_coupon_id'  => $template->id,
            'created_by'          => $this->superAdminId(),
        ]);
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
            'created_by'    => $this->superAdminId(),
        ]);
    }

    /**
     * ID của super_admin dùng làm created_by cho coupon tự động cấp,
     * tránh created_by = null khiến coupon hiển thị ở mọi role (xem CouponResource::getEloquentQuery).
     */
    private function superAdminId(): ?string
    {
        return User::role(config('filament-shield.super_admin.name'))->value('id');
    }
}
