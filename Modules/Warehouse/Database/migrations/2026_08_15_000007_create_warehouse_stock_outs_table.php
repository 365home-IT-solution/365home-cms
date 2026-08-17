<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_outs', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->string('code')->unique();
            // "Người xuất cho" — có thể gắn 1 Employee cụ thể VÀ/HOẶC 1 phòng (product_id, xem FK
            // bên dưới), hoặc chỉ ghi tự do qua issued_to nếu không gắn bản ghi nào cụ thể.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->uuid('product_id')->nullable()->comment('Phòng nhận hàng (products.id)');
            $table->string('issued_to')->nullable()->comment('Bộ phận / người nhận');
            $table->dateTime('issued_at');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_outs');
    }
};
