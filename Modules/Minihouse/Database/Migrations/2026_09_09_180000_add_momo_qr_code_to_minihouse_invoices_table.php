<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MoMo trả về 2 thứ HOÀN TOÀN KHÁC NHAU trong response captureWallet: "payUrl" (link mở TRÌNH
// DUYỆT tới trang thanh toán MoMo) và "qrCodeUrl" (KHÔNG PHẢI url ảnh — là CHUỖI PAYLOAD phải tự vẽ
// thành ảnh QR, đúng chuẩn app MoMo/camera nhận diện được). Bản đầu tiên nhầm dùng payUrl để vẽ QR —
// camera/app MoMo quét vào báo "Thông tin không hợp lệ" vì đó là 1 link web thường, không đúng
// schema QR thanh toán MoMo. Thêm cột riêng lưu đúng payload này, giống payos_qr_code (khác
// payos_checkout_url) đã làm cho PayOS.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->text('momo_qr_code')->nullable()->after('momo_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn('momo_qr_code');
        });
    }
};
