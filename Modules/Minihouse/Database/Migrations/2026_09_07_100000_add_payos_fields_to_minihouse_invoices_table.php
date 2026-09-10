<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép tạo mã QR PayOS để khách chuyển khoản trực tiếp cho 1 hoá đơn — xem
// InvoicePayOsService. Chỉ lưu request PayOS ĐANG HIỆU LỰC gần nhất của hoá đơn (tạo QR mới thì ghi
// đè, không giữ lịch sử nhiều lần tạo) — khi khách chuyển khoản thành công, webhook tự tạo 1 dòng
// InvoicePayment thật (xem PayOsWebhookController), không lưu trạng thái thanh toán ở đây.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            // unique — PayOS yêu cầu orderCode không trùng trong TOÀN BỘ tài khoản PayOS (dùng
            // chung 1 tài khoản PayOS với Order bên Home, xem PaymentConfiguration) — sinh theo dải
            // số RIÊNG (luôn bắt đầu bằng số 9, 9 chữ số) để không bao giờ trùng với order_code của
            // Order (tối đa 8 chữ số) dù không tra chéo sang bảng orders mỗi lần tạo.
            $table->unsignedBigInteger('payos_order_code')->nullable()->unique()->after('paid_at');
            $table->string('payos_checkout_url')->nullable()->after('payos_order_code');
            $table->text('payos_qr_code')->nullable()->after('payos_checkout_url');
            $table->timestamp('payos_expired_at')->nullable()->after('payos_qr_code');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn(['payos_order_code', 'payos_checkout_url', 'payos_qr_code', 'payos_expired_at']);
        });
    }
};
