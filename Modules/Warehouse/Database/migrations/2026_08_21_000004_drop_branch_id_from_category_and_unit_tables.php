<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhóm vật tư (warehouse_categories) và Đơn vị tính (warehouse_units) là DANH MỤC DÙNG CHUNG cho
// toàn bộ đối tác (mọi chi nhánh của đối tác cùng thấy/dùng chung 1 danh sách) — KHÔNG tách theo
// chi nhánh. Chỉ vật tư (warehouse_items) và các phiếu nhập/xuất/kiểm kê mới thực sự thuộc về 1
// chi nhánh cụ thể (tồn kho vật lý khác nhau ở mỗi chi nhánh). Migration
// 2026_08_21_000001_add_branch_id_to_warehouse_tables đã thêm nhầm branch_id cho cả 2 bảng danh
// mục dùng chung này — gỡ bỏ lại đây.
return new class extends Migration
{
    private const TABLES = ['warehouse_categories', 'warehouse_units'];

    public function up(): void
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

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('branch_id')->nullable()->after('partner_id');
                $blueprint->foreign('branch_id')->references('id')->on('categories')->nullOnDelete();
            });
        }
    }
};
