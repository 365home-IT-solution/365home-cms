<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIÁ NGÀY LỄ, TẾT của bộ giá "Theo ngày" (room_style = 2): danh sách dòng { name, recurring, from, to, price } —
 * xem App\Support\HolidayPrices. Ngày thuộc 1 dòng thì tính giá dòng đó, thắng giá theo thứ (weekday_prices).
 * Bộ giá giữ cấu hình (price_board_sheets); price_board_items là bản SINH RA cho từng phòng và là nơi StayPriceResolver đọc.
 * Chỉ có tác dụng khi bật giai đoạn 2 của bảng giá (config price_boards.phase2).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'holiday_prices')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->json('holiday_prices')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'holiday_prices')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('holiday_prices');
                });
            }
        }
    }
};
