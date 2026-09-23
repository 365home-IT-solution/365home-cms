<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_return_items', function (Blueprint $table) {
            $table->id();
            // Tên constraint FK RÚT GỌN tường minh — tên mặc định Laravel tự sinh
            // ("{prefix}warehouse_stock_return_items_warehouse_stock_return_id_foreign") VƯỢT QUÁ
            // giới hạn 64 ký tự của MySQL khi cộng thêm tiền tố bảng "cms_", gây lỗi "Identifier
            // name ... is too long" (đã xác nhận thực tế khi chạy migrate).
            $table->foreignId('warehouse_stock_return_id')
                ->constrained('warehouse_stock_returns', indexName: 'wsri_stock_return_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('warehouse_item_id')
                ->constrained('warehouse_items', indexName: 'wsri_item_id_foreign');
            // Trỏ lại đúng dòng đã xuất trước đó (VD: xuất 2 chai, khách dùng 1, hoàn 1) — dùng để
            // chặn hoàn nhiều hơn số đã xuất, xem WarehouseStockReturnItem::creating()/updating().
            // Nullable vì vẫn cho phép hoàn "không truy vết" (hàng phát hiện thừa/không rõ nguồn gốc
            // xuất), khi đó không giới hạn theo phiếu xuất nào.
            $table->foreignId('warehouse_stock_out_item_id')->nullable()
                ->constrained('warehouse_stock_out_items', indexName: 'wsri_stock_out_item_id_foreign')
                ->nullOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->string('note')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_return_items');
    }
};
