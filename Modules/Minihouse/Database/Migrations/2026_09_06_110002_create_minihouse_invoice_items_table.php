<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Từng dòng phụ thu ĐÃ ÁP vào 1 hoá đơn cụ thể — lưu SNAPSHOT name/amount tại thời điểm lập hoá
// đơn (không tham chiếu sống vào minihouse_surcharges.amount), để sau này đổi giá phụ thu trong
// danh mục không làm sai lệch số tiền hoá đơn cũ đã lập/đã thu. surcharge_id giữ lại chỉ để biết
// dòng này gốc từ phụ thu nào (nullOnDelete: xoá phụ thu gốc không xoá luôn dòng đã lên hoá đơn).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('minihouse_invoices')->cascadeOnDelete();
            $table->foreignId('surcharge_id')->nullable()->constrained('minihouse_surcharges')->nullOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_invoice_items');
    }
};
