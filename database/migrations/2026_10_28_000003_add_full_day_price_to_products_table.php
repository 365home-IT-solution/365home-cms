<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIÁ CẢ NGÀY của phòng theo KHUNG GIỜ: khách chọn đủ mọi khung giờ trong một ngày thì tính đúng giá này (thay vì cộng từng khung và thay cho "Giảm giá đặt trọn").
 * Null = không dùng. Xem Product::getEffectiveFullBookingDiscountAttribute().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'full_day_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('full_day_price', 12, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'full_day_price')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('full_day_price');
            });
        }
    }
};
