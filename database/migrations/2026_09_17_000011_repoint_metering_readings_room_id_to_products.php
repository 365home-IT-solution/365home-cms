<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Module Metering (log chỉ số điện nước) được tạo SAU đợt rà soát ban đầu của kế hoạch gộp
// MiniHouse-Homestay nên bị bỏ sót khỏi Giai đoạn 3 — metering_readings.room_id vẫn trỏ
// minihouse_rooms (bigint), gây lỗi "Argument #1 ($roomId) must be of type int, string given" khi
// InvoiceGenerationService gọi MeteringReading::forRoomAndMonth() với Room.id kiểu ULID mới. Áp
// đúng khuôn xử lý như migration 2026_09_17_000007 (đổi cột room_id sang char(36) trỏ products).
return new class extends Migration
{
    public function up(): void
    {
        // Bảng thuộc module Metering — có thể chưa tồn tại nếu module đó nạp migration SAU migration
        // gộp này trên môi trường hiện tại. Bỏ qua hẳn, không có gì để repoint.
        if (! Schema::hasTable('metering_readings')) {
            return;
        }

        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . 'metering_readings';
        $productsTbl   = $prefix . 'products';

        $column = DB::selectOne(
            "SELECT DATA_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($column && $column->type === 'char') {
            return; // đã chạy rồi (idempotent)
        }

        // Cột room_id chưa từng tồn tại (bảng vừa được module Metering tạo mới đúng lúc, hoặc lệch
        // thứ tự migrate so với dev) — không có dữ liệu cũ để backfill, tạo thẳng đúng cột đích rồi
        // dừng, bỏ qua toàn bộ phần đổi tên/backfill bên dưới.
        if (! $column) {
            Schema::table('metering_readings', function (Blueprint $table) {
                $table->char('room_id', 36);
                $table->foreign('room_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unique(['room_id', 'month']);
            });

            return;
        }

        Schema::table('metering_readings', function (Blueprint $table) {
            $table->char('room_id_new', 36)->nullable()->after('room_id');
        });

        DB::statement("
            UPDATE `{$physicalTable}` t
            LEFT JOIN `{$productsTbl}` p ON p.legacy_minihouse_room_id = t.room_id
            SET t.room_id_new = p.id
        ");

        // dropColumn('room_id') xoá LUÔN unique(room_id, month) cùng lúc (1 trong 2 cột của index
        // biến mất) — phải tạo lại index đó SAU khi đổi tên/kiểu cột xong, không tự động giữ lại.
        Schema::table('metering_readings', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropUnique(['room_id', 'month']);
            $table->dropColumn('room_id');
        });

        DB::statement("ALTER TABLE `{$physicalTable}` CHANGE `room_id_new` `room_id` CHAR(36) NOT NULL");

        Schema::table('metering_readings', function (Blueprint $table) {
            $table->foreign('room_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['room_id', 'month']);
        });
    }

    public function down(): void
    {
        // Không hoàn tác kiểu cột/giá trị — xem ghi chú tương tự ở migration 2026_09_17_000007.
    }
};
