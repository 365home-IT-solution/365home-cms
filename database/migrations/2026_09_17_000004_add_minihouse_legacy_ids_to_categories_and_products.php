<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cột TẠM để Giai đoạn 2 (data migration) và Giai đoạn 3 (repoint 14 bảng phụ thuộc) tra ngược
// "bản ghi minihouse_buildings/minihouse_rooms cũ nào ứng với category/product mới nào" — xoá ở
// Giai đoạn 5 sau khi toàn bộ quá trình gộp đã xác nhận ổn định, không còn nơi nào cần tra lại.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'legacy_minihouse_building_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_minihouse_building_id')->nullable()->index();
            });
        }

        if (! Schema::hasColumn('products', 'legacy_minihouse_room_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_minihouse_room_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'legacy_minihouse_building_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('legacy_minihouse_building_id');
            });
        }

        if (Schema::hasColumn('products', 'legacy_minihouse_room_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('legacy_minihouse_room_id');
            });
        }
    }
};
