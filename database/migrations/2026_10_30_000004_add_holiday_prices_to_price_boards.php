<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIÁ NGÀY LỄ, TẾT DÙNG CHUNG của cả bảng giá: khai báo 1 lần, áp cho MỌI bộ giá theo ngày / theo giờ của bảng
 * (cùng định dạng App\Support\HolidayPrices). Bộ giá nào có dòng lễ riêng (price_board_sheets.holiday_prices) thì dòng
 * riêng ghi đè dòng chung cùng ngày. Giá cố định (mode = price) chỉ áp cho bộ giá theo ngày; % / số tiền áp cho cả hai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_boards') && ! Schema::hasColumn('price_boards', 'holiday_prices')) {
            Schema::table('price_boards', function (Blueprint $table) {
                $table->json('holiday_prices')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('price_boards') && Schema::hasColumn('price_boards', 'holiday_prices')) {
            Schema::table('price_boards', function (Blueprint $table) {
                $table->dropColumn('holiday_prices');
            });
        }
    }
};
