<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cấu hình SMS Brandname RIÊNG cho MiniHouse — dùng eSMS.vn (esms.vn), TÁCH BIỆT khỏi mọi cấu hình
// SMS khác của Home nếu có. Bảng luôn CHỈ 1 DÒNG (id=1, xem SmsSetting::current()) — cùng mẫu
// ZaloSetting: Brandname là tên hiển thị khi gửi (đã đăng ký + được eSMS duyệt trước), ApiKey/
// SecretKey lấy từ tài khoản eSMS Business.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_sms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('api_key')->nullable();
            $table->string('secret_key')->nullable();
            $table->string('brandname')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_sms_settings');
    }
};
