<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lịch sử gia hạn hợp đồng — trước đây gia hạn = sửa tay end_date/monthly_price trực tiếp trên
// Contract, không giữ lại giá/ngày cũ để đối chiếu sau này. Bảng này CHỈ lưu nhật ký (ai gia hạn,
// lúc nào, từ ngày/giá nào sang ngày/giá nào) — Contract.end_date/monthly_price vẫn là nguồn dữ
// liệu SỐNG duy nhất mà mọi nơi khác (hoá đơn, cảnh báo sắp hết hạn...) đang đọc, không đổi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->date('old_end_date')->nullable();
            $table->date('new_end_date');
            $table->decimal('old_monthly_price', 12, 2)->nullable();
            $table->decimal('new_monthly_price', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_renewals');
    }
};
