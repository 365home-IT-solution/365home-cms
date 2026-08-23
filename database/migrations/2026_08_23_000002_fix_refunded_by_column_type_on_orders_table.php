<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // orders.refunded_by lưu users.id — nhưng users.id là UUID (char 36), không phải số nguyên (xem
    // 2025_01_01_000001_create_users_table.php: $table->uuid('id')->primary()). Migration gốc
    // (2026_08_23_000000_add_refund_to_orders_table.php) tạo nhầm unsignedBigInteger, gây lỗi
    // TypeError khi OrderRefundService::refund() nhận $admin->id (string) — sửa lại đúng kiểu.
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'refunded_by')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}orders MODIFY COLUMN refunded_by CHAR(36) NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'refunded_by')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}orders MODIFY COLUMN refunded_by BIGINT UNSIGNED NULL");
    }
};
