<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Danh mục vật tư (tồn kho THEO TỪNG TOÀ NHÀ, khác categories/units dùng chung) — mirror
// Modules\Minihouse\App\Models\WarehouseItem bên Home, đổi partner_id+branch_id thành building_id.
// "quantity" là CỘT SỐ DƯ CHẠY (running balance) — mọi phiếu nhập/xuất/kiểm kê/hoàn trả CỘNG/TRỪ
// thẳng vào cột này qua increment()/decrement() (KHÔNG tính lại từ lịch sử) — xem
// Modules\Minihouse\App\Models\WarehouseItem::booted().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id');
            $table->foreign('building_id', 'mhw_items_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('sku', 100)->nullable();
            $table->string('name');
            $table->foreignId('warehouse_category_id')->nullable()
                ->constrained('minihouse_warehouse_categories', 'id', 'mhw_items_cat_fk')->nullOnDelete();
            $table->foreignId('warehouse_unit_id')->nullable()
                ->constrained('minihouse_warehouse_units', 'id', 'mhw_items_unit_fk')->nullOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('quantity_in_use', 15, 2)->default(0);
            $table->decimal('min_quantity', 15, 2)->default(0);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_items');
    }
};
