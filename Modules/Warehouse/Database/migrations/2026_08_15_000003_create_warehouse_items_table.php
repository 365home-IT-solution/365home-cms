<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->foreignId('warehouse_category_id')->nullable()->constrained('warehouse_categories')->nullOnDelete();
            $table->foreignId('warehouse_unit_id')->nullable()->constrained('warehouse_units')->nullOnDelete();
            $table->decimal('quantity', 15, 2)->default(0)->comment('Tồn kho hiện tại');
            $table->decimal('min_quantity', 15, 2)->default(0)->comment('Ngưỡng tồn tối thiểu để cảnh báo');
            $table->decimal('unit_price', 15, 2)->nullable()->comment('Đơn giá tham chiếu (giá nhập gần nhất)');
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_items');
    }
};
