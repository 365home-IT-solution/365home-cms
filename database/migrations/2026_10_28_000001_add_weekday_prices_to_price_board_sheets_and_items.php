<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIÁ THEO THỨ của bộ giá "Theo ngày" (room_style = 2): {"1": giá Thứ 2, … , "7": giá Chủ nhật} (ISO). Null = một giá cho mọi ngày (cột price).
 * Bộ giá giữ cấu hình (price_board_sheets); price_board_items là bản SINH RA cho từng phòng và là nơi StayPriceResolver đọc khi tính giá đêm.
 * Chỉ có tác dụng khi bật giai đoạn 2 của bảng giá (config price_boards.phase2).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'weekday_prices')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->json('weekday_prices')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'weekday_prices')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('weekday_prices');
                });
            }
        }
    }
};
