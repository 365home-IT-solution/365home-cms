<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bổ sung 2 cột còn thiếu cho người đi cùng (khung giờ qua đêm) — cccd_front_2/cccd_back_2
     * đã có sẵn từ 2026_07_16_170643_add_cccd_2_to_orders_table, nhưng cccd_data_2 (dữ liệu quét
     * QR CCCD) và buyer_phone_2 (SĐT người đi cùng) chưa từng có migration nào tạo trong nhánh
     * hiện tại — CccdDeclarationService::upsertFromOrder() và luồng đặt phòng (web + API) đều cần
     * 2 cột này để tự khai báo lưu trú cho khách thứ 2. hasColumn guard vì môi trường dev cục bộ
     * đã có sẵn 2 cột này (từ 1 migration cũ đã chạy rồi sau đó bị xoá khỏi code).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cccd_data_2')) {
                $table->longText('cccd_data_2')->nullable()->after('cccd_back_2');
            }
            if (! Schema::hasColumn('orders', 'buyer_phone_2')) {
                $table->string('buyer_phone_2')->nullable()->after('cccd_data_2');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'buyer_phone_2')) {
                $table->dropColumn('buyer_phone_2');
            }
            if (Schema::hasColumn('orders', 'cccd_data_2')) {
                $table->dropColumn('cccd_data_2');
            }
        });
    }
};
