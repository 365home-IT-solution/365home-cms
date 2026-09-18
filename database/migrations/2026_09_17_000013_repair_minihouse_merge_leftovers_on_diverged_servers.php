<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Migration DỌN DẸP — vá lại 3 bảng bị migration 2026_09_17_000007/000011/000012 để dở dang trên 1
// số môi trường (server đã chạy các migration đó nhiều lần, mỗi lần bị lỗi giữa chừng do lệch thứ tự
// dữ liệu — xem báo cáo lỗi "Unknown column 'room_id'"). Idempotent hoàn toàn: chạy trên môi trường
// ĐÃ đúng (dev local) sẽ không làm gì cả; chạy trên môi trường ĐANG dở dang sẽ hoàn tất đúng phần
// còn thiếu. KHÔNG cố khôi phục dữ liệu cũ — theo yêu cầu, toàn bộ dữ liệu nghiệp vụ MiniHouse sẽ
// được nhập lại từ đầu qua panel sau khi deploy, nên bản ghi nào không thoả ràng buộc đích (room_id
// rỗng) sẽ bị XOÁ thẳng thay vì cố gắng backfill (không còn dữ liệu gốc để backfill được nữa).
return new class extends Migration
{
    public function up(): void
    {
        $this->fixMinihouseContracts();
        $this->fixMinihouseRoomAmenity();
        $this->fixMeteringReadings();
    }

    // room_id còn kiểu char(36) NHƯNG bị NULLABLE (đáng lẽ NOT NULL) do 1 lần chạy dở dang để lại cột
    // thừa room_id_new — bảng minihouse_contracts hiện KHÔNG còn dữ liệu (0 dòng, đã xác nhận) nên xoá
    // thẳng cột thừa + ép lại đúng ràng buộc, không cần backfill gì cả.
    private function fixMinihouseContracts(): void
    {
        if (! Schema::hasTable('minihouse_contracts')) {
            return;
        }

        if (Schema::hasColumn('minihouse_contracts', 'room_id_new')) {
            Schema::table('minihouse_contracts', function (Blueprint $table) {
                $table->dropColumn('room_id_new');
            });
        }

        DB::table('minihouse_contracts')->whereNull('room_id')->delete();

        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . 'minihouse_contracts';

        $nullable = DB::selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($nullable && $nullable->nullable === 'YES') {
            DB::statement("ALTER TABLE `{$physicalTable}` MODIFY `room_id` CHAR(36) NOT NULL");
        }

        $this->addForeignKeyIfMissing('minihouse_contracts', 'room_id', 'products', 'cascade');
    }

    // room_id NULLABLE (đáng lẽ NOT NULL vì là 1 nửa khoá chính ghép) + thiếu hẳn PRIMARY KEY —
    // những dòng room_id rỗng hiện có là rác (không gán được cho phòng nào), xoá thẳng theo đúng yêu
    // cầu "dữ liệu MiniHouse nhập lại từ đầu, không cần chuyển gì cả".
    private function fixMinihouseRoomAmenity(): void
    {
        if (! Schema::hasTable('minihouse_room_amenity')) {
            return;
        }

        DB::table('minihouse_room_amenity')->whereNull('room_id')->delete();

        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . 'minihouse_room_amenity';

        $nullable = DB::selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($nullable && $nullable->nullable === 'YES') {
            DB::statement("ALTER TABLE `{$physicalTable}` MODIFY `room_id` CHAR(36) NOT NULL");
        }

        $hasPrimaryKey = DB::selectOne(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
            [$physicalTable]
        );

        if (! $hasPrimaryKey) {
            Schema::table('minihouse_room_amenity', function (Blueprint $table) {
                $table->primary(['room_id', 'amenity_id']);
            });
        }

        $this->addForeignKeyIfMissing('minihouse_room_amenity', 'room_id', 'products', 'cascade');
    }

    // room_id NULLABLE (đáng lẽ NOT NULL) + thiếu FK + thiếu unique(room_id, month) — 1 dòng rác
    // room_id rỗng hiện có, xoá thẳng theo đúng yêu cầu "nhập lại từ đầu".
    private function fixMeteringReadings(): void
    {
        if (! Schema::hasTable('metering_readings')) {
            return;
        }

        DB::table('metering_readings')->whereNull('room_id')->delete();

        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . 'metering_readings';

        $nullable = DB::selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($nullable && $nullable->nullable === 'YES') {
            DB::statement("ALTER TABLE `{$physicalTable}` MODIFY `room_id` CHAR(36) NOT NULL");
        }

        // Laravel tự đặt tên index theo TÊN BẢNG THẬT (đã có tiền tố 'cms_'), không phải tên bảng
        // logic trong code — phải ghép đúng $physicalTable vào đây, không phải 'metering_readings'.
        $hasUnique = DB::selectOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$physicalTable, $physicalTable . '_room_id_month_unique']
        );

        if (! $hasUnique) {
            Schema::table('metering_readings', function (Blueprint $table) {
                $table->unique(['room_id', 'month']);
            });
        }

        $this->addForeignKeyIfMissing('metering_readings', 'room_id', 'products', 'cascade');
    }

    private function addForeignKeyIfMissing(string $table, string $column, string $references, string $onDelete): void
    {
        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . $table;

        $hasFk = DB::selectOne(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1",
            [$physicalTable, $column]
        );

        if ($hasFk) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($column, $references, $onDelete) {
            $fk = $t->foreign($column)->references('id')->on($references);
            $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Migration dọn dẹp — không có trạng thái "trước đó" ý nghĩa để hoàn tác.
    }
};
