<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lịch sử dùng mã giảm giá dành riêng cho trang admin "Lịch sử dùng mã giảm giá" + xuất Excel —
// tách biệt với coupon_usages (bảng nghiệp vụ nội bộ, phục vụ filter "used_voucher" trên OrderTable,
// có thể bị XÓA khi coupon trên đơn thay đổi/hoàn). Bảng này là NHẬT KÝ, không bao giờ tự xóa dòng —
// khi 1 lượt dùng bị hoàn (đơn chuyển từ paid/deposit sang cancelled/refunded/failed) chỉ set
// reversed_at. coupon_id/order_id dùng nullOnDelete (KHÔNG cascade) vì mọi thông tin cần cho báo cáo
// đã snapshot sẵn vào các cột string riêng (code, coupon_name, order_code, customer_name...) — xóa
// coupon/đơn gốc sau này không được phép kéo mất luôn lịch sử đã xuất Excel trước đó.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->string('code', 50);
            $table->string('coupon_name')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_code')->nullable();
            $table->char('customer_id', 36)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->integer('discount_amount')->nullable();
            $table->integer('order_amount')->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->uuid('partner_id')->nullable();
            $table->timestamp('used_at');
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->index('used_at');
            $table->index('code');
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usage_logs');
    }
};
