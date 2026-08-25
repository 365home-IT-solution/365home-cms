<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'warehouse_categories',
        'warehouse_units',
        'warehouse_items',
        'warehouse_stock_ins',
        'warehouse_stock_outs',
        'warehouse_stock_checks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                // Chi nhánh = category gốc (parent_id null, category_type 'product'), theo đúng
                // quy ước "chi nhánh" đã dùng ở Employee/DataPermission/Product trong toàn hệ thống.
                // Nullable vì record cũ / partner chỉ có 1 chi nhánh có thể chưa cần gán.
                $blueprint->unsignedBigInteger('branch_id')->nullable()->after('partner_id');
                $blueprint->foreign('branch_id')->references('id')->on('categories')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['branch_id']);
                $blueprint->dropColumn('branch_id');
            });
        }
    }
};
