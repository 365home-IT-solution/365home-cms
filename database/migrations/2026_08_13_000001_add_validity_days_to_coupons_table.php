<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hỗ trợ NHIỀU voucher/hạng thành viên (vd hạng Vàng: 4 voucher khác giá trị) với hạn dùng tính
// theo NGÀY TỪNG KHÁCH lên hạng (không phải ngày cố định chung) — coupon được admin tạo qua
// MembershipTierResource/CouponResource rồi gắn vào 1 hạng (membership_tier_coupon) đóng vai trò
// "mẫu" (template); khi 1 khách đạt hạng, MembershipService nhân bản 1 coupon RIÊNG cho khách đó
// (customer_id + end_at = lúc lên hạng + validity_days) thay vì gắn chung 1 coupon dùng chung hạn cố
// định qua coupon_customers như trước — xem MembershipService::grantTemplateCoupon().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('validity_days')->nullable()->after('end_at');
            $table->foreignId('template_coupon_id')->nullable()->after('customer_id')
                ->constrained('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['template_coupon_id']);
            $table->dropColumn(['validity_days', 'template_coupon_id']);
        });
    }
};
