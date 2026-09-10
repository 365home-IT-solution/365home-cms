<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mỗi toà nhà có thể thuộc 1 CHỦ SỞ HỮU khác nhau, thu tiền về TÀI KHOẢN NGÂN HÀNG riêng của chủ đó
// (không dùng chung 1 tài khoản cho cả hệ thống như PayOS) — phục vụ in phiếu thu tiền phòng trọ kèm
// mã QR chuyển khoản đúng chủ toà nhà tương ứng (xem InvoiceContentRenderer, VietnameseBanks).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('water_unit_price');
            // owner_bank_bin = mã BIN theo chuẩn Napas/VietQR (VD 970436 = Vietcombank) — dùng để
            // dựng URL ảnh QR qua img.vietqr.io, chọn từ danh sách cố định (VietnameseBanks) chứ
            // không cho gõ tay để tránh sai mã khiến QR không quét được.
            $table->string('owner_bank_bin')->nullable()->after('owner_name');
            // Lưu kèm tên hiển thị ngắn của ngân hàng (VD "Vietcombank") ngay lúc chọn — tránh phải
            // tra lại bảng VietnameseBanks theo bin mỗi lần hiển thị.
            $table->string('owner_bank_name')->nullable()->after('owner_bank_bin');
            $table->string('owner_bank_account_number')->nullable()->after('owner_bank_name');
            $table->string('owner_bank_account_holder')->nullable()->after('owner_bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn([
                'owner_name',
                'owner_bank_bin',
                'owner_bank_name',
                'owner_bank_account_number',
                'owner_bank_account_holder',
            ]);
        });
    }
};
