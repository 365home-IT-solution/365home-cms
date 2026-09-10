<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Portal khách thuê (đăng nhập bằng OTP qua SĐT, xem TenantOtpService) — Tenant giờ ĐÓNG VAI TRÒ
// thêm 1 "tài khoản đăng nhập" (guard riêng "tenant", xem config/auth.php), cần remember_token để
// dùng được cơ chế "Ghi nhớ đăng nhập" chuẩn của Laravel (Illuminate\Auth\Authenticatable) — không
// đổi gì ý nghĩa CŨ của bảng này (vẫn là hồ sơ khách thuê trong nghiệp vụ cho thuê).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn('remember_token');
        });
    }
};
