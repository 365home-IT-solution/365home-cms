<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_in_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_in_id')->constrained('warehouse_stock_ins')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')->constrained('warehouse_items');
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('note')->nullable();
            // Độ chính xác micro giây (6) — dùng làm tiêu chí sắp xếp phụ (entry_created_at) trong
            // VIEW warehouse_stock_movements khi nhiều dòng biến động trùng occurred_at (rất phổ
            // biến vì occurred_at thường chỉ là 1 ngày do người dùng chọn). Xem
            // WarehouseStockInItem::$dateFormat — Eloquent chỉ thật sự ghi được micro giây khi model
            // khai báo $dateFormat có phần .u, cột DB đủ độ phân giải là điều kiện cần nhưng chưa đủ.
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_in_items');
    }
};
