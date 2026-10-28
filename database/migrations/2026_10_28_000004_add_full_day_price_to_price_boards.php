<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GIÁ CẢ NGÀY trong bảng giá (bộ giá theo giờ, room_style = 1): khách chọn ĐỦ mọi khung trong ngày thì tính đúng giá này.
 * price_board_sheets: giá bộ giá khai báo (kèm fields_set). price_board_items: bản sinh ra cho từng phòng; 0 = "không dùng", null = chưa biết (không đụng giá của phòng).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'full_day_price')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->decimal('full_day_price', 12, 2)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['price_board_sheets', 'price_board_items'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'full_day_price')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropColumn('full_day_price');
                });
            }
        }
    }
};
