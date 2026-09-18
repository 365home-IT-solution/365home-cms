<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Giai đoạn 3 của kế hoạch gộp MiniHouse-Homestay: đổi cột room_id của 6 bảng phụ thuộc từ bigint
// (trỏ minihouse_rooms) sang char(36) (trỏ products.id — Product dùng HasUlids, ULID 26 ký tự vẫn
// vừa char(36)). KHÁC bước building_id (chỉ đổi giá trị) — bước này phải đổi CẢ KIỂU CỘT, nên làm
// theo khuôn: thêm cột mới -> backfill -> xoá cột+FK cũ -> đổi tên cột mới -> thêm FK mới.
// doctrine/dbal KHÔNG có sẵn trong dự án nên dùng ALTER TABLE thô để đổi NULL/NOT NULL sau backfill
// thay vì Blueprint::change().
return new class extends Migration
{
    // table => [nullable gốc, onDelete gốc]
    private array $tables = [
        'minihouse_tenants'         => [true, 'null'],
        'minihouse_contracts'       => [false, 'cascade'],
        'minihouse_reminders'       => [true, 'null'],
        'minihouse_room_assets'     => [false, 'cascade'],
        'minihouse_tenant_feedbacks' => [true, 'null'],
        'minihouse_panorama_scenes' => [true, 'null'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => [$nullable, $onDelete]) {
            $this->repoint($table, $nullable, $onDelete);
        }
    }

    private function repoint(string $table, bool $nullable, string $onDelete): void
    {
        // Bảng có thể chưa tồn tại nếu môi trường này nạp migration module SAU migration gộp này —
        // bỏ qua hẳn, không có gì để repoint.
        if (! Schema::hasTable($table)) {
            return;
        }

        // Raw SQL KHÔNG tự thêm tiền tố bảng (vd 'cms_') — phải tự ghép DB::getTablePrefix().
        $prefix        = DB::getTablePrefix();
        $physicalTable = $prefix . $table;
        $productsTbl   = $prefix . 'products';

        // Idempotent: cột room_id đã là char(36) rồi (chạy lại migration) thì bỏ qua.
        $column = DB::selectOne(
            "SELECT DATA_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'",
            [$physicalTable]
        );

        if ($column && $column->type === 'char') {
            return;
        }

        // Cột room_id chưa từng tồn tại trên bảng này ở môi trường này (lệch thứ tự migrate module so
        // với dev, hoặc bảng vừa được tạo mới đúng lúc) — không có dữ liệu cũ để backfill, tạo thẳng
        // đúng cột đích rồi dừng.
        if (! $column) {
            Schema::table($table, function (Blueprint $t) use ($nullable, $onDelete) {
                $t->char('room_id', 36)->nullable($nullable);
                $fk = $t->foreign('room_id')->references('id')->on('products');
                $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
            });

            return;
        }

        $hasFk = DB::selectOne(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'room_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1",
            [$physicalTable]
        );

        Schema::table($table, function (Blueprint $t) {
            $t->char('room_id_new', 36)->nullable()->after('room_id');
        });

        DB::statement("
            UPDATE `{$physicalTable}` t
            LEFT JOIN `{$productsTbl}` p ON p.legacy_minihouse_room_id = t.room_id
            SET t.room_id_new = p.id
        ");

        // Không phải mọi bảng đều thật sự có ràng buộc khoá ngoại đúng như migration gốc khai báo
        // (đã xác nhận qua minihouse_surcharges.building_id) — chỉ dropForeign() khi tồn tại thật.
        Schema::table($table, function (Blueprint $t) use ($hasFk) {
            if ($hasFk) {
                $t->dropForeign(['room_id']);
            }
            $t->dropColumn('room_id');
        });

        // Đổi tên CỘT MỚI thành 'room_id' bằng ALTER TABLE ... CHANGE thô (không dùng
        // Schema::renameColumn(), vốn cần doctrine/dbal cho vài driver — dự án không cài package
        // này) — gộp luôn bước ép NOT NULL (nếu cột gốc không nullable) vào cùng 1 câu lệnh.
        $nullSpec = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$physicalTable}` CHANGE `room_id_new` `room_id` CHAR(36) {$nullSpec}");

        Schema::table($table, function (Blueprint $t) use ($onDelete) {
            $fk = $t->foreign('room_id')->references('id')->on('products');
            $onDelete === 'cascade' ? $fk->cascadeOnDelete() : $fk->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Không hoàn tác kiểu cột/giá trị — đảo ngược an toàn đòi hỏi giữ bản sao room_id bigint gốc.
        // Chỉ dùng khi huỷ giữa chừng Giai đoạn 3, xem ghi chú tương tự ở migration building_id.
    }
};
