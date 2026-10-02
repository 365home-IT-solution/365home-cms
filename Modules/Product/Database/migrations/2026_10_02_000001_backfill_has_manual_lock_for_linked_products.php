<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Phòng đã được gán vào bộ mật khẩu khóa thủ công qua "Cấp mã mở hàng loạt" / Import Excel / modal
// "Thêm mới" ở trang Khóa cổng nhưng chưa bật has_manual_lock (các đường đó trước đây không bật cờ)
// → đơn của phòng báo "Chưa có mã cổng". Bật lại cờ cho các phòng đó; code mới đã tự bật qua
// ManualLockPassword::markProductsAsManualLock().
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('has_manual_lock', false)
            ->whereNull('lock_id')
            ->whereIn('id', DB::table('manual_lock_password_product')->select('product_id'))
            ->update(['has_manual_lock' => true]);
    }

    public function down(): void
    {
        // Không hoàn tác — không phân biệt được phòng nào được bật bởi migration này.
    }
};
