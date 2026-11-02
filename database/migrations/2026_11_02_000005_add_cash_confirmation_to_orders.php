<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thanh toán tiền mặt tại quầy (payment_method = cod): ghi ai xác nhận đã thu, lúc nào, ghi chú và nguồn (nhân viên bấm / hệ thống tự xác nhận vì khách
// đã ở xong). cash_reminded_at: đã nhắc đối tác lúc tới giờ nhận phòng mà đơn chưa thu tiền (nhắc một lần).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cash_confirmed_at')->nullable();
            $table->uuid('cash_confirmed_by')->nullable();
            $table->string('cash_confirm_source', 20)->nullable();
            $table->string('cash_confirm_note')->nullable();
            $table->timestamp('cash_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cash_confirmed_at', 'cash_confirmed_by', 'cash_confirm_source', 'cash_confirm_note', 'cash_reminded_at']);
        });
    }
};
