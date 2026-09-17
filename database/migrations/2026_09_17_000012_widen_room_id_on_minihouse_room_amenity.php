<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Room::amenities() vẫn dùng ĐÚNG hệ Amenity/minihouse_room_amenity riêng của MiniHouse như trước
// khi gộp bảng (xem ghi chú ở Room::amenities()) — chỉ cần đổi KIỂU CỘT room_id sang char(36) cho
// khớp id mới của Room (ULID, đã đổi hẳn Room sang dùng bảng products). Áp đúng khuôn xử lý như
// 2026_09_17_000007 (composite PRIMARY KEY (room_id, amenity_id), không có unique index riêng nào
// khác cần lo mất khi đổi cột).
return new class extends Migration
{
    public function up(): void
    {
        // Bảng có thể chưa tồn tại nếu môi trường này chạy migration module MUỘN HƠN migration gộp
        // này (thứ tự nạp migration giữa "database/migrations" gốc và migration module phụ thuộc vào
        // cách nwidart/laravel-modules đăng ký, không phải lúc nào cũng đảm bảo y hệt mọi môi
        // trường) — bỏ qua hẳn, không có gì để đổi kiểu cột.
        if (! Schema::hasTable('minihouse_room_amenity')) {
            return;
        }

        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . 'minihouse_room_amenity';
        $productsTbl   = $prefix . 'products';

        $column = DB::selectOne(
            "SELECT DATA_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($column && $column->type === 'char') {
            return; // đã chạy rồi (idempotent)
        }

        // Cột room_id chưa từng tồn tại (bảng vừa được nwidart tạo mới vừa lúc, hoặc bị lệch thứ tự
        // migrate so với môi trường dev) — không có dữ liệu cũ nào để chuyển, tạo thẳng đúng cột đích
        // (char36, trỏ products) rồi dừng, bỏ qua toàn bộ phần đổi tên/backfill bên dưới.
        if (! $column) {
            $hasPrimaryKey = DB::selectOne(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
                [$physicalTable]
            );

            Schema::table('minihouse_room_amenity', function (Blueprint $table) use ($hasPrimaryKey) {
                $table->char('room_id', 36);
                $table->foreign('room_id')->references('id')->on('products')->cascadeOnDelete();

                if (! $hasPrimaryKey) {
                    $table->primary(['room_id', 'amenity_id']);
                }
            });

            return;
        }

        Schema::table('minihouse_room_amenity', function (Blueprint $table) {
            $table->char('room_id_new', 36)->nullable()->after('room_id');
        });

        DB::statement("
            UPDATE `{$physicalTable}` t
            LEFT JOIN `{$productsTbl}` p ON p.legacy_minihouse_room_id = t.room_id
            SET t.room_id_new = p.id
        ");

        // Khoá chính ghép (room_id, amenity_id) — dropColumn('room_id') xoá luôn PRIMARY KEY, phải
        // tạo lại sau khi đổi tên/kiểu cột xong.
        Schema::table('minihouse_room_amenity', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropPrimary(['room_id', 'amenity_id']);
            $table->dropColumn('room_id');
        });

        DB::statement("ALTER TABLE `{$physicalTable}` CHANGE `room_id_new` `room_id` CHAR(36) NOT NULL");

        Schema::table('minihouse_room_amenity', function (Blueprint $table) {
            $table->primary(['room_id', 'amenity_id']);
            $table->foreign('room_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Không hoàn tác kiểu cột/giá trị — xem ghi chú tương tự ở migration 2026_09_17_000007.
    }
};
