<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Phiếu hoàn trả kho" — trường hợp thực tế: cấp cho phòng 2 chai nước, khách chỉ dùng 1, còn 1
// chưa dùng thì hoàn lại kho. Cấu trúc mirror y hệt warehouse_stock_outs (head/detail), có
// branch_id ngay từ đầu (khác warehouse_stock_outs — bảng đó phải retrofit thêm ở migration
// 2026_08_21_000001 vì tạo trước khi có khái niệm chi nhánh).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code')->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->uuid('product_id')->nullable()->comment('Phòng hoàn trả (products.id)');
            $table->string('returned_by')->nullable()->comment('Bộ phận / người hoàn trả');
            $table->dateTime('returned_at');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_returns');
    }
};
