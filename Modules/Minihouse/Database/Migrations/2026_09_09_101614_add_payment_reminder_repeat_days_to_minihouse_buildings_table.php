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
            // Số ngày LẶP LẠI nhắc đóng tiền nếu hoá đơn VẪN chưa thanh toán sau lần nhắc trước — VD
            // nhắc mùng 5, 3 ngày sau (mùng 8) vẫn chưa thấy thanh toán thì tự nhắc lại, lặp mỗi 3
            // ngày cho tới khi thanh toán. Khác payment_reminder_days_before (số ngày nhắc TRƯỚC hạn,
            // chỉ áp dụng 1 lần lúc lập hoá đơn) — trường này điều khiển việc LẶP LẠI SAU ĐÓ. NULL =
            // tắt, chỉ nhắc đúng 1 lần như hành vi cũ.
            $table->unsignedTinyInteger('payment_reminder_repeat_days')->nullable()->after('payment_reminder_days_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_repeat_days');
        });
    }
};
