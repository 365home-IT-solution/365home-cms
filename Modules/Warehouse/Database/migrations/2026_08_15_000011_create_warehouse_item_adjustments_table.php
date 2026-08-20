<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ghi lại MỌI lần cột warehouse_items.quantity bị sửa TRỰC TIẾP qua trang "Sửa vật tư" (không đi
// qua phiếu nhập/xuất/kiểm kê) — chống nhân viên tự ý sửa số tồn để che gian lận mà không để lại
// dấu vết. Xem WarehouseItem::updated() (chỉ bắt thay đổi qua $item->save()/update(), KHÔNG bắt
// các lệnh increment() nội bộ của WarehouseStock{In,Out,Check}Item vì increment() qua query builder
// không bắn Eloquent event — không có nguy cơ ghi trùng lặp với sổ nhật ký nhập/xuất/kiểm kê).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_item_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->foreignId('warehouse_item_id')->constrained('warehouse_items')->cascadeOnDelete();
            $table->decimal('old_quantity', 15, 2);
            $table->decimal('new_quantity', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Log chỉ-ghi, không có updated_at (WarehouseItemAdjustment::$timestamps = false) — độ
            // chính xác micro giây cùng lý do với các bảng *_items ở trên.
            $table->timestamp('created_at', 6)->useCurrent();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_item_adjustments');
    }
};
