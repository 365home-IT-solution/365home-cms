<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// apply_type là ENUM ở DB — thêm giá trị mới 'specific_rooms' (số nhiều, khác 'specific_room' số
// ít hiện có) để hỗ trợ 1 coupon áp dụng cho NHIỀU phòng cụ thể qua bảng coupon_products.
// Dùng DB::getTablePrefix() vì DB::statement() với tên bảng viết tay KHÔNG tự thêm prefix
// (config('database...prefix') = 'cms_' ở môi trường này) như Schema::table() tự làm.
return new class extends Migration
{
    public function up(): void
    {
        $table = DB::getTablePrefix() . 'coupons';
        DB::statement("ALTER TABLE {$table} MODIFY apply_type ENUM('all_rooms','specific_room','specific_rooms','specific_slot') NOT NULL DEFAULT 'all_rooms'");
    }

    public function down(): void
    {
        $table = DB::getTablePrefix() . 'coupons';
        DB::statement("ALTER TABLE {$table} MODIFY apply_type ENUM('all_rooms','specific_room','specific_slot') NOT NULL DEFAULT 'all_rooms'");
    }
};
