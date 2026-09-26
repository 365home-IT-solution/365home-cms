<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_stock_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_check_id')
                ->constrained('minihouse_warehouse_stock_checks', 'id', 'mhw_chki_chk_fk')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')
                ->constrained('minihouse_warehouse_items', 'id', 'mhw_chki_item_fk');
            $table->decimal('system_quantity', 15, 2);
            $table->decimal('actual_quantity', 15, 2);
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_stock_check_items');
    }
};
