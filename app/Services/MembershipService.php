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
     * Phát coupon có sẵn đã gắn vào hạng (cả 'auto' lẫn 'manual') cho toàn bộ customer đang giữ
     * hạng đó. Gọi khi admin gắn/gỡ coupon khỏi hạng trong Filament (afterCreate/afterSave).
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
     * Nút "Đồng bộ" ở trang Sửa hạng thành viên: rà lại từng khách ĐANG giữ hạng này, so với cấu
     * hình HIỆN TẠI ở "Coupon tự động cấp" (chỉ voucher mẫu nguồn 'auto' — KHÔNG tính mã gắn tay ở
     * "Mã giảm giá gắn thêm cho hạng") — khách nào thiếu voucher nào (vd dữ liệu cũ trước khi hạng
     * có đủ N voucher, khách chỉ mới được cấp 1) thì cấp bù đúng phần còn thiếu, không đụng tới
     * voucher khách đã có. Trả về số liệu để hiển thị lên Notification cho admin biết kết quả.
     *
     * @return array{customers_checked: int, customers_updated: int, vouchers_granted: int}
     */
    public function syncAutoVouchersForTierMembers(MembershipTier $tier): array
    {
        $templates = $tier->coupons()->wherePivot('source', 'auto')->get();

        $result = ['customers_checked' => 0, 'customers_updated' => 0, 'vouchers_granted' => 0];

        if ($templates->isEmpty()) {
            return $result;
        }

        Customer::where('membership_tier_id', $tier->id)
            ->get()
            ->each(function (Customer $customer) use ($templates, &$result) {
                $result['customers_checked']++;
                $grantedForThisCustomer = 0;

                foreach ($templates as $template) {
                    if ($this->grantTemplateCoupon($customer, $template)) {
                        $grantedForThisCustomer++;
                    }
                }

                if ($grantedForThisCustomer > 0) {
                    $result['customers_updated']++;
                    $result['vouchers_granted'] += $grantedForThisCustomer;
                }
            });

        return $result;
    }

    /**
     * Đọc voucher mẫu NGUỒN 'auto' (sinh ra từ "Coupon tự động cấp", phân biệt với coupon gắn tay
     * ở "Mã giảm giá gắn thêm cho hạng" qua cột pivot membership_tier_coupon.source) hiện đang gắn
     * vào $tier — dùng để hiển thị lại cho form sửa hạng (Filament Repeater / REST API).
     */
    public function voucherTemplatesToFormState(MembershipTier $tier): array
    {
        return $tier->coupons->where('pivot.source', 'auto')->map(fn (Coupon $c) => [
            'template_id'     => $c->id,
            'prefix'          => $c->code,
            'type'            => $c->type,
            'value'           => (float) $c->value,
            'min_order_value' => $c->min_order_value !== null ? (float) $c->min_order_value : null,
            'validity_days'   => $c->validity_days,
            'usage_limit'     => $c->usage_limit,
            'is_exclusive'    => (bool) $c->is_exclusive,
        ])->values()->toArray();
    }

    /**
     * Đồng bộ voucher mẫu NGUỒN 'auto' của $tier theo danh sách dòng vừa submit (mỗi dòng: 'prefix',
     * 'type', 'value', 'min_order_value'?, 'validity_days', 'usage_limit'?, 'is_exclusive'?, và
     * 'template_id' nếu là sửa dòng đã có): dòng có 'template_id' → update coupon mẫu đã có; dòng
     * mới → tạo coupon mẫu mới; coupon mẫu cũ (source='auto') không còn trong danh sách → gỡ khỏi
     * hạng (KHÔNG xoá — có thể đã phát bản sao cho khách). CHỈ động vào pivot rows source='auto' —
     * không đụng tới các mã gắn tay (source='manual', xem syncManualCoupons()), tránh 2 khu vực cấu
     * hình ghi đè lẫn nhau khi lưu. Gọi xong nhớ gọi thêm distributeTierCoupons($tier) để phát ngay
     * cho khách đang giữ hạng.
     */
    public function syncVoucherTemplates(MembershipTier $tier, array $rows): void
    {
        $keepIds = [];

        foreach ($rows as $row) {
            $fields = [
                'type'            => $row['type'] ?? 'fixed',
                'value'           => $row['value'] ?? 0,
                'min_order_value' => $row['min_order_value'] ?? null,
                'validity_days'   => $row['validity_days'] ?? 30,
                'usage_limit'     => $row['usage_limit'] ?? null,
                'is_exclusive'    => (bool) ($row['is_exclusive'] ?? true),
                'apply_type'      => 'all_rooms',
                'is_active'       => true,
            ];

            $templateId = $row['template_id'] ?? null;
            $template   = $templateId ? Coupon::find($templateId) : null;

            if ($template) {
                $template->update($fields);
                $keepIds[] = $template->id;
                continue;
            }

            $prefix = strtoupper(Str::slug($row['prefix'] ?? 'VC', ''));
            $name   = 'Voucher ' . $tier->name . ' — ' . ($row['prefix'] ?? '');

            $template = Coupon::create($fields + [
                'code'       => $this->generateUniqueTemplateCode($prefix),
                'name'       => $name,
                'used_count' => 0,
                'start_at'   => now(),
                'created_by' => $this->superAdminId(),
            ]);

            $keepIds[] = $template->id;
        }

        $currentAutoIds = $tier->coupons()->wherePivot('source', 'auto')->pluck('coupons.id')->all();

        $toDetach = array_diff($currentAutoIds, $keepIds);
        if (! empty($toDetach)) {
            $tier->coupons()->detach($toDetach);
        }

        foreach ($keepIds as $id) {
            $tier->coupons()->syncWithoutDetaching([$id => ['source' => 'auto']]);
        }
    }

    /**
     * Đọc id mã giảm giá NGUỒN 'manual' hiện đang gắn vào $tier — dùng để hiển thị lại cho form sửa
     * hạng (Filament Select / REST API).
     */
    public function manualCouponIdsToFormState(MembershipTier $tier): array
    {
        return $tier->coupons()->wherePivot('source', 'manual')->pluck('coupons.id')->all();
    }

    /**
     * Đồng bộ danh sách mã gắn tay (nguồn 'manual') theo danh sách id vừa submit — CHỈ động vào
     * pivot rows source='manual', không đụng tới voucher tự động (source='auto', xem
     * syncVoucherTemplates()).
     */
    public function syncManualCoupons(MembershipTier $tier, array $couponIds): void
    {
        $currentManualIds = $tier->coupons()->wherePivot('source', 'manual')->pluck('coupons.id')->all();

        $toDetach = array_diff($currentManualIds, $couponIds);
        if (! empty($toDetach)) {
            $tier->coupons()->detach($toDetach);
        }

        foreach ($couponIds as $id) {
            $tier->coupons()->syncWithoutDetaching([$id => ['source' => 'manual']]);
        }
    }

    private function generateUniqueTemplateCode(string $prefix): string
    {
        do {
            $code = Str::limit($prefix, 10, '') . strtoupper(Str::random(6));
        } while (Coupon::where('code', $code)->exists());

        return $code;
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
     * Cấp 1 coupon MẪU của hạng cho 1 customer, trả về true nếu vừa cấp mới (false nếu khách đã có
     * sẵn từ trước — không cấp trùng).
     * - Coupon mẫu KHÔNG có validity_days: giữ hành vi cũ — gắn thẳng coupon DÙNG CHUNG (hạn cố
     *   định theo start_at/end_at của chính coupon mẫu) qua bảng coupon_customers.
     * - Coupon mẫu CÓ validity_days: nhân bản 1 coupon RIÊNG cho customer này (customer_id + hạn =
     *   thời điểm cấp + validity_days), hỗ trợ nhiều voucher/hạng mà mỗi voucher hạn tính theo
     *   ngày từng khách lên hạng thay vì 1 ngày cố định dùng chung. Kiểm tra template_coupon_id
     *   trước khi tạo để tránh cấp trùng nếu hàm này được gọi lại nhiều lần cho cùng 1 lượt lên hạng
     *   (vd distributeTierCoupons() chạy lại khi admin sửa hạng, hoặc nút "Đồng bộ").
     */
    private function grantTemplateCoupon(Customer $customer, Coupon $template): bool
    {
        if (! $template->validity_days) {
            $changes = $customer->coupons()->syncWithoutDetaching([$template->id]);

            return ! empty($changes['attached']);
        }

        $alreadyIssued = Coupon::where('customer_id', $customer->id)
            ->where('template_coupon_id', $template->id)
            ->exists();

        if ($alreadyIssued) {
            return false;
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
            'is_exclusive'        => $template->is_exclusive,
            'customer_id'         => $customer->id,
            'template_coupon_id'  => $template->id,
            'created_by'          => $this->superAdminId(),
        ]);

        return true;
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
            // Voucher chào mừng hạng luôn độc quyền — quy định "1 đơn chỉ áp dụng 1 voucher"
            // áp dụng cho mọi voucher cấp theo hạng, kể cả voucher chào mừng.
            'is_exclusive'  => true,
            'customer_id'   => $customer->id,
            'created_by'    => $this->superAdminId(),
        ]);
    }

    /**
     * Cấp thưởng đăng nhập định kỳ cho 1 khách, nếu hạng hiện tại của khách bật auto_issue_enabled
     * VÀ đã đủ auto_issue_interval_weeks tuần kể từ lần được cấp gần nhất (tra theo lịch sử coupon
     * có auto_issue_tier_id = hạng này, KHÔNG phải theo giờ/lịch cố định — kích hoạt bởi hành động
     * đăng nhập của khách). Gọi ngay khi khách đăng nhập thành công (xem
     * ZaloOtpController::verifyOtp()/login()) — nơi gọi PHẢI bọc try/catch vì lỗi ở đây không được
     * làm hỏng luồng đăng nhập.
     */
    public function grantLoginReward(Customer $customer): void
    {
        if (! $customer->membership_tier_id) {
            return;
        }

        $tier = MembershipTier::where('id', $customer->membership_tier_id)
            ->where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->first();

        if (! $tier || ! $tier->auto_issue_coupon_value || $tier->auto_issue_coupon_value <= 0) {
            return;
        }

        $intervalWeeks = max(1, (int) $tier->auto_issue_interval_weeks);

        $lastGranted = Coupon::where('customer_id', $customer->id)
            ->where('auto_issue_tier_id', $tier->id)
            ->latest('created_at')
            ->first();

        if ($lastGranted && $lastGranted->created_at->gt(now()->subWeeks($intervalWeeks))) {
            return;
        }

        $prefix = strtoupper(Str::slug($tier->name, ''));

        Coupon::create([
            'code'               => Str::limit($prefix, 10, '') . strtoupper(Str::random(6)),
            'name'               => 'Ưu đãi định kỳ hạng ' . $tier->name . ' — ' . $customer->fullname,
            'description'        => 'Coupon tự động cấp định kỳ cho hạng ' . $tier->name,
            'type'               => $tier->auto_issue_coupon_type ?: 'fixed',
            'value'              => $this->rollAutoIssueCouponValue($tier),
            'apply_type'         => 'all_rooms',
            'usage_limit'        => $tier->auto_issue_coupon_usage_limit,
            'used_count'         => 0,
            'start_at'           => now(),
            'end_at'             => $tier->auto_issue_coupon_days ? now()->addDays($tier->auto_issue_coupon_days) : null,
            'is_active'          => true,
            'is_exclusive'       => true,
            'customer_id'        => $customer->id,
            'auto_issue_tier_id' => $tier->id,
            'created_by'         => $this->superAdminId(),
        ]);

        app(NotificationFcmService::class)->sendToCustomer(
            $customer,
            $tier->auto_issue_notify_title ?: 'Ưu đãi dành cho hạng ' . $tier->name,
            $tier->auto_issue_notify_body ?: 'Bạn vừa nhận được một mã khuyến mãi mới dành riêng cho hạng thành viên của bạn. Kiểm tra ngay!',
            'membership_auto_coupon',
        );
    }

    /**
     * Random giá trị mã trong khoảng [auto_issue_coupon_value, auto_issue_coupon_value_max], làm
     * tròn về bội số 1.000đ. Nếu value_max trống hoặc <= value thì luôn trả về đúng value (không
     * random) — giữ đúng hành vi cố định 1 mức giá như trước khi có value_max.
     */
    private function rollAutoIssueCouponValue(MembershipTier $tier): float
    {
        $min = (float) $tier->auto_issue_coupon_value;
        $max = (float) $tier->auto_issue_coupon_value_max;

        if ($max <= $min) {
            return $min;
        }

        return random_int((int) round($min / 1000), (int) round($max / 1000)) * 1000;
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
