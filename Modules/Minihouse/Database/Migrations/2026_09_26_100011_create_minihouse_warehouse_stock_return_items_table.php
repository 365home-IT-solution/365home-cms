<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_return_id')
                ->constrained('minihouse_warehouse_stock_returns', 'id', 'mhw_reti_ret_fk')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')
                ->constrained('minihouse_warehouse_items', 'id', 'mhw_reti_item_fk');
            // Có giá trị = "hoàn từ đúng dòng đã xuất" (bị giới hạn KHÔNG ĐƯỢC vượt số đã xuất - đã
            // hoàn trước đó, xem WarehouseStockReturnItem::guardAgainstOverReturn()). NULL = hoàn trả
            // KHÔNG TRUY VẾT (vật tư dư tìm thấy) — không giới hạn số lượng.
            $table->foreignId('warehouse_stock_out_item_id')->nullable()
                ->constrained('minihouse_warehouse_stock_out_items', 'id', 'mhw_reti_souti_fk')->nullOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_return_items');
    }
};
