<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Gán tạm mỗi user (không phải super_admin, chưa có branch_id) vào chi nhánh ĐẦU TIÊN
        // (id nhỏ nhất) của đúng đối tác họ thuộc về — chỉ là giá trị khởi điểm hợp lý, người dùng
        // có thể sửa lại thủ công cho từng tài khoản trong Filament sau (User form / bulk action).
        $partners = DB::table('users')
            ->whereNull('branch_id')
            ->whereNotNull('partner_id')
            ->distinct()
            ->pluck('partner_id');

        foreach ($partners as $partnerId) {
            $branchId = DB::table('categories')
                ->where('partner_id', $partnerId)
                ->where('category_type', 'product')
                ->whereNull('parent_id')
                ->orderBy('id')
                ->value('id');

            if ($branchId === null) {
                continue;
            }

            DB::table('users')
                ->where('partner_id', $partnerId)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branchId]);
        }
    }

    public function down(): void
    {
        // Không hoàn tác dữ liệu backfill — chỉ là gán mặc định ban đầu.
    }
};
