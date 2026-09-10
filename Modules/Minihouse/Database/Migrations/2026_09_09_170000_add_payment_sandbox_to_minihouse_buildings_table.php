<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép test MoMo/VNPay bằng dữ liệu SANDBOX trước khi đăng ký doanh nghiệp thật xong — MoMo/
// VNPay dùng domain API + bộ khoá HOÀN TOÀN KHÁC nhau giữa môi trường thử nghiệm và thật (khác
// PayOS chỉ có 1 domain duy nhất cho cả 2 trường hợp), nên phải có cờ riêng để InvoiceMomoService/
// InvoiceVnpayService biết gọi đúng domain nào ứng với bộ khoá đang khai báo. Đổi lại credential
// thật sau khi đăng ký doanh nghiệp xong thì TẮT cờ này đi, không cần đổi gì khác.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->boolean('payment_sandbox')->default(false)->after('vnpay_hash_secret');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('payment_sandbox');
        });
    }
};
