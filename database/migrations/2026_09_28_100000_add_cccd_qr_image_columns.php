<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // cccd_qr_image = ảnh mặt CCCD có mã QR — nguồn duy nhất để quét ra cccd_data ở luồng khách
    // (web/app). cccd_front/cccd_back giữ lại cho admin lưu ảnh đầy đủ 2 mặt để tra cứu/đối chứng.
    // Dữ liệu cũ giữ nguyên ở cccd_front/cccd_back (không đoán mặt nào có QR).
    private const TABLES = ['orders', 'order_guest_cccds', 'customers', 'customer_companions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('cccd_qr_image')->nullable()->after('cccd_back');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('cccd_qr_image');
            });
        }
    }
};
