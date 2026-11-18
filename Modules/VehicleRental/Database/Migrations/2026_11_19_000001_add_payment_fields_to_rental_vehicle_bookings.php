<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thanh toán PayOS (tài khoản chung của 365) + hoàn tiền cho đơn thuê xe. Hoàn tiền thực hiện thủ công (chuyển khoản),
// hệ thống chỉ ghi số cần hoàn (refund_amount) và thời điểm đã hoàn (refunded_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('payos_order_code')->nullable()->after('paid_amount')->comment('Dải mã bắt đầu bằng 5');
            $table->text('payos_checkout_url')->nullable()->after('payos_order_code');
            $table->text('payos_qr_code')->nullable()->after('payos_checkout_url');
            $table->dateTime('payos_expires_at')->nullable()->after('payos_qr_code');
            $table->dateTime('paid_at')->nullable()->after('payos_expires_at');
            $table->unsignedInteger('refund_amount')->default(0)->after('paid_at')->comment('Số tiền cần hoàn cho khách');
            $table->dateTime('refunded_at')->nullable()->after('refund_amount');

            $table->unique('payos_order_code', 'rvb_payos_code_uq');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dropUnique('rvb_payos_code_uq');
            $table->dropColumn(['payos_order_code', 'payos_checkout_url', 'payos_qr_code', 'payos_expires_at', 'paid_at', 'refund_amount', 'refunded_at']);
        });
    }
};
