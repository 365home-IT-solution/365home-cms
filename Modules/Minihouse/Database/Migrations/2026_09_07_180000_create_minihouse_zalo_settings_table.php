<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cấu hình Zalo OA/ZNS RIÊNG cho MiniHouse — hoàn toàn TÁCH BIỆT khỏi Zalo OA của Home (app/Settings/
// ZaloSettings.php, config/services.php 'zalo', dùng cho OTP đăng nhập + ZNS đặt phòng bên Home).
// MiniHouse có thể là 1 thương hiệu/kênh liên hệ khác Home, nên dùng OA riêng, App ID/Secret riêng,
// Access/Refresh Token riêng (tự refresh, xem MinihouseZaloTokenService) và 3 mẫu ZNS riêng cho 3
// loại nhắc việc (Reminder::TYPE_PAYMENT/TYPE_CONTRACT/TYPE_MAINTENANCE) — xem MinihouseZaloService.
// Bảng luôn CHỈ 1 DÒNG (id=1, xem ZaloSetting::current()) — giống PaymentConfiguration của Home
// nhưng KHÔNG dùng chung bảng đó, tránh 2 module (Home/MiniHouse) cùng ghi đè config của nhau.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_zalo_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_id')->nullable();
            $table->string('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('template_payment_reminder')->nullable();
            $table->string('template_contract_expiry')->nullable();
            $table->string('template_maintenance')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_zalo_settings');
    }
};
