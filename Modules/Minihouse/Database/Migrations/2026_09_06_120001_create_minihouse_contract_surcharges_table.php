<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Danh sách phụ thu ĐỊNH KỲ mà hợp đồng này phải trả hàng tháng (VD: có gửi xe, không dùng
// internet) — KHÔNG snapshot số tiền (khác minihouse_invoice_items ở tầng hoá đơn): đây là danh
// sách "đang áp dụng", đổi giá trong danh mục Phụ thu thì tháng sau tự theo giá mới — chỉ có hoá
// đơn ĐÃ LẬP mới snapshot số tiền cố định tại thời điểm đó.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_surcharges', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->foreignId('surcharge_id')->constrained('minihouse_surcharges')->cascadeOnDelete();
            $table->primary(['contract_id', 'surcharge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_surcharges');
    }
};
