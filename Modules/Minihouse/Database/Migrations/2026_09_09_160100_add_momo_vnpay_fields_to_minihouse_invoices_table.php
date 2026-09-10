<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lưu trạng thái link/QR MoMo và VNPay đang mở của TỪNG hoá đơn — cùng mẫu payos_order_code/
// payos_checkout_url/payos_expired_at (xem migration add_payos_fields_to_minihouse_invoices_table),
// tách riêng cột theo từng cổng vì 1 hoá đơn có thể được tạo link ở NHIỀU cổng khác nhau (khách đổi ý
// muốn quét QR ví khác) — không dùng chung 1 bộ cột "payment_gateway_ref" tổng quát để tránh mơ hồ
// khi hiện lại đúng link/QR nào đang hiệu lực cho đúng cổng nào.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->string('momo_order_id')->nullable()->after('payos_expired_at');
            $table->text('momo_pay_url')->nullable()->after('momo_order_id');
            $table->timestamp('momo_expired_at')->nullable()->after('momo_pay_url');

            $table->string('vnpay_txn_ref')->nullable()->after('momo_expired_at');
            $table->text('vnpay_payment_url')->nullable()->after('vnpay_txn_ref');
            $table->timestamp('vnpay_expired_at')->nullable()->after('vnpay_payment_url');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn(['momo_order_id', 'momo_pay_url', 'momo_expired_at', 'vnpay_txn_ref', 'vnpay_payment_url', 'vnpay_expired_at']);
        });
    }
};
