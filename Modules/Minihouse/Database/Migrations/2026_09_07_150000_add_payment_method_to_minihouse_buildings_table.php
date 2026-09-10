<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Chọn RÕ RÀNG kiểu thanh toán áp dụng cho toà nhà này ('vietqr' | 'payos' | null = chưa cấu hình)
// — thay vì để hệ thống tự suy luận theo trường nào đang được điền (dễ mơ hồ nếu cả 2 mục cùng có
// dữ liệu). Xem Building::activePaymentMethod().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('owner_bank_account_holder');
        });

        // Toà nhà nào TRƯỚC ĐÂY đã lỡ điền thông tin ngân hàng (khi tính năng chưa có nút chọn) thì
        // tự đặt sẵn 'vietqr' — giữ đúng hành vi cũ, không bắt phải vào sửa lại thủ công.
        DB::table('minihouse_buildings')
            ->whereNotNull('owner_bank_bin')
            ->whereNotNull('owner_bank_account_number')
            ->update(['payment_method' => 'vietqr']);
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
