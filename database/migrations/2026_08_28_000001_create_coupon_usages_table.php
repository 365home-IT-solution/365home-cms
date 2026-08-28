<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            // null = khách vãng lai (đơn không gắn Customer nào, xem GuestBookingController).
            $table->char('customer_id', 36)->nullable();
            $table->unsignedBigInteger('order_id');
            // Chi nhánh, copy từ orders.category_id lúc ghi — cùng quy ước với
            // Dashboard::baseQuery()/ATotalWidgets::branchFilteredOrderQuery().
            $table->unsignedBigInteger('category_id')->nullable();
            // Snapshot mã tại thời điểm dùng (coupon có thể đổi code sau này).
            $table->string('code', 50);
            // NULL cho dữ liệu backfill từ orders cũ — orders không lưu số tiền đã giảm theo
            // từng coupon nên không truy hồi được cho lịch sử, chỉ biết được ở lượt dùng MỚI.
            $table->integer('discount_amount')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['order_id', 'coupon_id']);
            $table->index('customer_id');
            $table->index('used_at');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
