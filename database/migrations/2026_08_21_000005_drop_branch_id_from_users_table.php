<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// users.branch_id (thêm ở 2026_08_21_000002) giả định 1 tài khoản chỉ quản lý ĐÚNG 1 chi nhánh —
// sai thực tế: 1 tài khoản (đặc biệt chủ đối tác) có thể quản lý NHIỀU chi nhánh. Gỡ cột 1-giá-trị
// này, dùng lại quan hệ nhiều-chi-nhánh đã có sẵn (Modules\DataPermission\Entities\UserBranchPermission
// qua User::allowedBranchIds()/rootProductCategoryIds()) làm nguồn xác định "tài khoản này quản lý
// những chi nhánh nào" cho cả Warehouse lẫn phần còn lại của hệ thống — nhất quán 1 nguồn duy nhất.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'branch_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'branch_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('partner_id');
            $table->foreign('branch_id')->references('id')->on('categories')->nullOnDelete();
        });
    }
};
