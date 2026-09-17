<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Giai đoạn 3 của kế hoạch gộp MiniHouse-Homestay: đổi ràng buộc building_id của 6 bảng phụ thuộc
// từ trỏ minihouse_buildings sang trỏ categories (bảng đích mới của "toà nhà") — categories.id CÙNG
// KIỂU bigint như minihouse_buildings.id nên chỉ cần remap GIÁ TRỊ, không đổi kiểu cột. Giữ ĐÚNG
// hành vi xoá (cascade/null) như ràng buộc gốc của từng bảng.
// (minihouse_rooms.building_id KHÔNG nằm trong danh sách này — cả bảng minihouse_rooms sẽ bị xoá
// hẳn ở Giai đoạn 5, không cần repoint cột của 1 bảng sắp xoá.)
return new class extends Migration
{
    // table => delete behavior gốc (đúng như minihouse_buildings migration)
    private array $tables = [
        'minihouse_surcharges'      => 'cascade',
        'minihouse_user_buildings'  => 'cascade',
        'minihouse_transactions'    => 'null',
        'minihouse_activity_logs'   => 'null',
        'minihouse_announcements'   => 'null',
        'minihouse_panorama_scenes' => 'cascade',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $onDelete) {
            $this->repoint($table, $onDelete);
        }
    }

    private function repoint(string $table, string $onDelete): void
    {
        // Raw SQL (UPDATE/information_schema) KHÔNG tự thêm tiền tố bảng (vd 'cms_') như
        // Schema::table()/->on()/->constrained() làm — phải tự ghép DB::getTablePrefix().
        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . $table;
        $categoriesTbl = $prefix . 'categories';

        // Idempotent: nếu FK đã trỏ categories rồi (chạy lại migration) thì bỏ qua.
        $fk = DB::selectOne(
            "SELECT REFERENCED_TABLE_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'building_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1",
            [$physicalTable]
        );

        if ($fk && $fk->ref === $categoriesTbl) {
            return;
        }

        // minihouse_surcharges.building_id được xác nhận KHÔNG có ràng buộc khoá ngoại thật trong
        // DB (dù migration gốc có khai ->constrained()) — chỉ dropForeign() khi thật sự tồn tại,
        // tránh lỗi "Can't DROP ...; check that column/key exists".
        if ($fk) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['building_id']);
            });
        }

        DB::statement("
            UPDATE `{$physicalTable}` t
            INNER JOIN `{$categoriesTbl}` c ON c.legacy_minihouse_building_id = t.building_id
            SET t.building_id = c.id
        ");

        Schema::table($table, function (Blueprint $t) use ($onDelete) {
            $fk = $t->foreign('building_id')->references('id')->on('categories');
            $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Không hoàn tác giá trị đã remap (cần bản sao lưu building_id gốc để khôi phục đúng) — chỉ
        // trả FK về trỏ minihouse_buildings, dùng cho trường hợp huỷ giữa chừng Giai đoạn 3 khi
        // minihouse_buildings vẫn còn tồn tại (chưa qua Giai đoạn 5).
        foreach ($this->tables as $table => $onDelete) {
            Schema::table($table, function (Blueprint $t) use ($onDelete) {
                $t->dropForeign(['building_id']);
                $fk = $t->foreign('building_id')->references('id')->on('minihouse_buildings');
                $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
            });
        }
    }
};
