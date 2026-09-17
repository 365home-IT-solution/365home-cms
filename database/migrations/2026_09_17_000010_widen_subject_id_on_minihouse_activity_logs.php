<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// minihouse_activity_logs.subject_id was unsignedBigInteger (mọi model MiniHouse trước đây đều dùng
// id tự tăng). Sau khi gộp Room vào products, Room.id là chuỗi ULID (26 ký tự) — không vừa cột
// bigint ("Data truncated for column 'subject_id'"). Nới thành VARCHAR(36) đủ chứa CẢ bigint (ép
// chuỗi) LẪN ULID, không ảnh hưởng các dòng log cũ (MySQL tự đổi bigint hiện có sang chuỗi số).
// Dùng ALTER TABLE thô (không qua Blueprint::change(), cần doctrine/dbal — dự án không cài).
return new class extends Migration
{
    public function up(): void
    {
        $table = DB::getTablePrefix() . 'minihouse_activity_logs';
        DB::statement("ALTER TABLE `{$table}` MODIFY `subject_id` VARCHAR(36) NULL");
    }

    public function down(): void
    {
        $table = DB::getTablePrefix() . 'minihouse_activity_logs';
        DB::statement("ALTER TABLE `{$table}` MODIFY `subject_id` BIGINT UNSIGNED NULL");
    }
};
