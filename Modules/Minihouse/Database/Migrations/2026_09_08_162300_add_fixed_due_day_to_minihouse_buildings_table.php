<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            // CHỈ áp dụng khi billing_cycle_type = CALENDAR_MONTH — ngày thu tiền CỐ ĐỊNH trong
            // tháng (VD 10 = mùng 10 hàng tháng) thay vì mặc định mùng 1 (= period_start). Không ảnh
            // hưởng cách tính TIỀN PHÒNG (vẫn theo đúng tháng dương lịch/prorate như cũ) — chỉ đổi
            // NGÀY ĐẾN HẠN dùng để tính remind_date tự động (xem Invoice::dueDate()). Giới hạn 1-28
            // để luôn hợp lệ với cả tháng 2 (không có ngày 29-31 mọi năm).
            $table->unsignedTinyInteger('fixed_due_day')->nullable()->after('payment_reminder_days_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('fixed_due_day');
        });
    }
};
