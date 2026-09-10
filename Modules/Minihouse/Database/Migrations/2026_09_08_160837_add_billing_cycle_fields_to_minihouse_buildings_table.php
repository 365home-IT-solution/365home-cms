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
            // 'calendar_month' (mặc định, hành vi cũ — lập hoá đơn theo đúng tháng dương lịch, mùng 1
            // đến cuối tháng) hoặc 'anniversary_date' (theo ngày bắt đầu CỦA TỪNG hợp đồng — mỗi
            // khách 1 mốc riêng theo ngày dọn vào, phổ biến ở nhà trọ nhỏ) — xem
            // InvoiceGenerationService, Building::BILLING_CYCLE_*.
            $table->string('billing_cycle_type')->default('calendar_month')->after('payment_method');
            // Số ngày nhắc TRƯỚC ngày đến hạn (period_start) của mỗi hoá đơn — có giá trị thì
            // InvoiceObserver TỰ TẠO 1 Reminder loại "Nhắc đóng tiền" ngay khi hoá đơn được lập, thay
            // vì nhân viên phải tự nhớ tạo tay (nhất là khi mỗi toà đến hạn 1 ngày khác nhau). NULL =
            // tắt, không tự tạo nhắc việc cho toà này.
            $table->unsignedTinyInteger('payment_reminder_days_before')->nullable()->after('billing_cycle_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['billing_cycle_type', 'payment_reminder_days_before']);
        });
    }
};
