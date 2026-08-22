<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'branch_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Chi nhánh mặc định của tài khoản (category gốc, category_type 'product').
            // super_admin: null (không thuộc chi nhánh nào, xem/chọn được mọi chi nhánh).
            // Tài khoản đối tác thường: bắt buộc có giá trị để Warehouse tự gán khi tạo phiếu.
            $table->unsignedBigInteger('branch_id')->nullable()->after('partner_id');
            $table->foreign('branch_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'branch_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
