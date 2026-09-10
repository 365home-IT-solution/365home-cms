<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mẫu ZNS RIÊNG cho OTP đăng nhập Portal khách thuê — Zalo phân loại mẫu "OTP" thành 1 nhóm duyệt
// KHÁC 3 mẫu nhắc việc hiện có (chỉ được chứa mã xác thực + thời hạn, không kèm nội dung khác), nên
// không dùng chung được với template_payment_reminder/template_contract_expiry/template_maintenance.
// Xem MinihouseZaloService::sendOtp(), TenantOtpService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_zalo_settings', function (Blueprint $table) {
            $table->string('template_otp')->nullable()->after('template_maintenance');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_zalo_settings', function (Blueprint $table) {
            $table->dropColumn('template_otp');
        });
    }
};
