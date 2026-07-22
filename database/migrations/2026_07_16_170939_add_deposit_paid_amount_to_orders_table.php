<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 'full_amount' nay là TỔNG GIÁ CỐ ĐỊNH của đơn (không đổi từ lúc tạo) — số tiền cọc
            // THỰC TẾ đã thu (khi admin tự điều chỉnh tay ở form sửa đơn) cần 1 cột riêng để lưu,
            // không còn dùng chung full_amount như trước nữa. NULL = chưa từng bị admin sửa tay,
            // dùng mặc định Order::depositDueAmount() (full_amount * deposit_percent).
            $table->unsignedBigInteger('deposit_paid_amount')->nullable()->after('deposit_percent')
                ->comment('Số tiền cọc THỰC TẾ đã thu, chỉ set khi admin tự điều chỉnh tay. NULL = dùng mặc định full_amount * deposit_percent.');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('deposit_paid_amount');
        });
    }
};
