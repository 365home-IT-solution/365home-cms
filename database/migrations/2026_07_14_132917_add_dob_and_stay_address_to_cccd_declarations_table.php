<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            // "Ngày, tháng, năm sinh" và "Địa chỉ lưu trú" (địa chỉ CHI NHÁNH/phòng khách đang ở,
            // KHÁC với "Nơi thường trú" của khách) — cả 2 đều là nội dung BẮT BUỘC theo Luật Cư
            // trú nhưng trước đây chưa có cột riêng: ngày sinh bị gộp chung vào chuỗi 'info', còn
            // địa chỉ lưu trú hoàn toàn chưa được lưu (chỉ có room_number, không có địa chỉ chi
            // nhánh thật).
            $table->string('date_of_birth')->nullable()->after('info');
            $table->string('stay_address')->nullable()->after('room_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'stay_address']);
        });
    }
};
