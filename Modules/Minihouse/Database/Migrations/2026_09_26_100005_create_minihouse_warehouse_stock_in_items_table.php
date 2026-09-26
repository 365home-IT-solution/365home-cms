<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // timestamps(6) — giây phần triệu, dùng làm khoá sắp xếp phụ ở VIEW
        // minihouse_warehouse_stock_movements khi nhiều dòng cùng "occurred_at".
        Schema::create('minihouse_warehouse_stock_in_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_in_id')
                ->constrained('minihouse_warehouse_stock_ins', 'id', 'mhw_sini_sin_fk')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')
                ->constrained('minihouse_warehouse_items', 'id', 'mhw_sini_item_fk');
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_in_items');
    }
};
