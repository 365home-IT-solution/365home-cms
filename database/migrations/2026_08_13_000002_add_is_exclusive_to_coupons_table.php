<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép khách áp NHIỀU mã giảm giá/đơn trở lại (đã từng chặn cứng 1 mã/đơn — nay đổi hướng:
// mặc định vẫn cho stack nhiều mã, chỉ mã nào được đánh dấu is_exclusive=true mới CẤM dùng chung
// với bất kỳ mã nào khác — xem BookingController/GuestBookingController/OrderController::store()|
// update() và Livewire\ProductDetail::applyCoupon()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_exclusive')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('is_exclusive');
        });
    }
};
