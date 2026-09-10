<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép khách thuê tự đặt mật khẩu ĐĂNG NHẬP THAY THẾ cho OTP (Portal vẫn cho chọn 1 trong 2 mỗi
// lần đăng nhập, xem TenantAuthController) — mục tiêu giảm chi phí gửi Zalo/SMS khi khách quay lại
// nhiều lần. NULLable vì khách chưa từng đặt mật khẩu vẫn đăng nhập được bình thường qua OTP.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->string('password')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
