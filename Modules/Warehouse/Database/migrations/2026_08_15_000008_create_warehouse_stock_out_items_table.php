<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_out_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_stock_out_id')->constrained('warehouse_stock_outs')->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')->constrained('warehouse_items');
            // "Lý do xuất" thuộc về TỪNG DÒNG chứ không phải cả phiếu — 1 phiếu xuất có thể gồm
            // nhiều vật tư với lý do khác nhau (vừa hao hụt vừa housekeeping bình thường trong cùng
            // 1 lượt dọn phòng), gộp chung 1 lý do cho cả phiếu sai bản chất nghiệp vụ.
            $table->string('reason')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_out_items');
    }
};
