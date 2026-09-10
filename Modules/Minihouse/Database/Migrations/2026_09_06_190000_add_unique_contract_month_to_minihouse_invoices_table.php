<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chặn tạo trùng hoá đơn cùng hợp đồng + tháng ở tầng DB — trước đây chỉ kiểm tra bằng 1 câu
// ->exists() trước khi ->create() ở InvoiceGenerationService, không có gì chặn 2 lần lập hoá đơn
// hàng loạt chạy chồng nhau (lịch cron ngày 1 hàng tháng + bấm tay) tạo trùng 2 hoá đơn cùng tháng
// cho 1 hợp đồng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->unique(['contract_id', 'month'], 'minihouse_invoices_contract_month_unique');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropUnique('minihouse_invoices_contract_month_unique');
        });
    }
};
