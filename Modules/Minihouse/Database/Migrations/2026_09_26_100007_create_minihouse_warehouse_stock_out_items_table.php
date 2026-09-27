<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "reason" nằm ở TỪNG DÒNG (không phải ở phiếu) — 1 phiếu xuất có thể trộn nhiều lý do khác nhau,
// mirror đúng thiết kế đã sửa bên Home (trước đó để ở phiếu là thiết kế sai).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_out_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_out_id')
                ->constrained('minihouse_warehouse_stock_outs', 'id', 'mhw_souti_sout_fk')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')
                ->constrained('minihouse_warehouse_items', 'id', 'mhw_souti_item_fk');
            $table->string('reason')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_out_items');
    }
};
