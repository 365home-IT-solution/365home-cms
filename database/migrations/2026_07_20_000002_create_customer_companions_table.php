<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CCCD người đi cùng đã LƯU SẴN vào hồ sơ khách hàng đăng nhập — tái sử dụng cho nhiều lần
        // đặt phòng qua đêm sau này, thay vì phải upload lại mỗi lần đặt (khác với luồng guest
        // không đăng nhập, upload CCCD người đi cùng riêng cho từng đơn qua order_guest_cccds).
        // Chỉ tạo bảng + model trong đợt này — API thêm/sửa/xoá companion làm sau.
        Schema::create('customer_companions', function (Blueprint $table) {
            $table->id();
            $table->char('customer_id', 36);
            $table->string('full_name')->nullable();
            $table->string('cccd_front');
            $table->string('cccd_back');
            $table->json('cccd_data')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_companions');
    }
};
