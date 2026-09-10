<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Danh mục phụ thu TÁI SỬ DỤNG theo từng toà nhà (phí rác, gửi xe, internet, quản lý...) — mỗi
// chuỗi/toà có thể đặt mức khác nhau. Chọn từ đây khi lập hoá đơn thay vì gõ tay 1 số gộp mỗi
// tháng — xem minihouse_invoice_items (bảng lưu SNAPSHOT tên/số tiền lúc áp vào từng hoá đơn, đổi
// giá phụ thu sau này không ảnh hưởng hoá đơn cũ).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_surcharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('minihouse_buildings')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_surcharges');
    }
};
