<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ảnh CCCD mặt có mã QR của Khách thuê (luồng 1 ảnh, song song với id_card_front/id_card_back) —
// cùng ý nghĩa với cột cccd_qr_image bên Home. Quét từ ảnh này trước, xem CccdScanMapper::scan().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->string('id_card_qr_image')->nullable()->after('id_card_back');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn('id_card_qr_image');
        });
    }
};
