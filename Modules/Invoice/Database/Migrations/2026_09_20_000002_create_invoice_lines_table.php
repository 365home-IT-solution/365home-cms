<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mỗi dòng snapshot lại đúng dữ liệu OrderItem TẠI THỜI ĐIỂM tạo hoá đơn nháp (tên, đơn giá, thuế
// suất...) — KHÔNG tham chiếu sống tới OrderItem, vì nếu sau này Order bị sửa (đổi giá, thêm dịch
// vụ) thì hoá đơn đã tạo trước đó không được phép tự đổi theo — order_item_id chỉ giữ lại để tra
// cứu nguồn gốc, không dùng để hiển thị.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();

            $table->string('description');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
