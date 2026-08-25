<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_check_id')->constrained('warehouse_stock_checks')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')->constrained('warehouse_items');
            $table->decimal('system_quantity', 15, 2)->comment('Tồn hệ thống tại thời điểm kiểm kê');
            $table->decimal('actual_quantity', 15, 2)->comment('Tồn thực tế đếm được');
            $table->decimal('difference', 15, 2)->default(0)->comment('actual_quantity - system_quantity');
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_check_items');
    }
};
