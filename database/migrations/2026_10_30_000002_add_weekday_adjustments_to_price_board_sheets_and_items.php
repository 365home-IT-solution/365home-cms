<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TĂNG/GIẢM GIÁ THEO THỨ của bộ giá (cả phòng theo ngày lẫn theo giờ): danh sách dòng { days, mode, value } —
 * xem App\Support\WeekdayAdjustments. Giữ nguyên giá gốc theo loại phòng, chỉ tăng vào các thứ chọn (VD Thứ 7, Chủ nhật).
 * Chỉ có tác dụng khi bật giai đoạn 2 của bảng giá (config price_boards.phase2).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'weekday_adjustments')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->json('weekday_adjustments')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'weekday_adjustments')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('weekday_adjustments');
                });
            }
        }
    }
};
