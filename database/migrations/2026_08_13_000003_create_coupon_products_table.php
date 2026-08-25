<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép 1 coupon áp dụng cho NHIỀU phòng cụ thể (apply_type mới 'specific_rooms', khác
// 'specific_room' số ít hiện có — chỉ 1 phòng qua cột coupons.room_id). products.id là char(36)
// nên khai báo tường minh cùng kiểu (không dùng foreignId mặc định bigint) để FK constraint khớp.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->char('product_id', 36);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_products');
    }
};
