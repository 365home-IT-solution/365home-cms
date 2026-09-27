<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhật ký PHÁT HIỆN chỉnh tay số lượng tồn — mirror WarehouseItemAdjustment (Home): mọi lần
// "quantity" trên minihouse_warehouse_items bị sửa qua save()/update() THÔNG THƯỜNG (không phải qua
// increment()/decrement() nội bộ của phiếu nhập/xuất/kiểm kê/hoàn trả) đều tự ghi 1 dòng ở đây — xem
// WarehouseItem::booted(). Bảng chỉ GHI, không có $timestamps updated_at (chỉ created_at).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_item_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_item_id')
                ->constrained('minihouse_warehouse_items', 'id', 'mhw_adj_item_fk')->cascadeOnDelete();
            $table->decimal('old_quantity', 15, 2);
            $table->decimal('new_quantity', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->text('note')->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by', 'mhw_adj_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_item_adjustments');
    }
};
