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
            // Số ngày TRƯỚC end_date để tự tạo 1 Reminder (type=het_han_hop_dong) gửi thẳng Zalo cho
            // KHÁCH THUÊ nhắc gia hạn — khác CheckOverdueContractsCommand (chỉ tạo nhắc việc SAU KHI
            // đã quá hạn, cho NHÂN VIÊN xử lý). NULL = tắt, không tự nhắc gia hạn trước hạn.
            $table->unsignedTinyInteger('contract_expiry_reminder_days_before')->nullable()->after('payment_reminder_repeat_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('contract_expiry_reminder_days_before');
        });
    }
};
