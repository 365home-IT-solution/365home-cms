<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_cleaning_logs')) {
            return;
        }

        Schema::create('room_cleaning_logs', function (Blueprint $table) {
            $table->id();

            $table->char('product_id', 36);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            // Đơn hàng đã kích hoạt lượt dọn này (audit) — null nếu được đánh dấu thủ công.
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();

            // Nhân viên xác nhận đã dọn xong — null cho tới khi có người xác nhận.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->dateTime('marked_for_cleaning_at');
            $table->dateTime('cleaned_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_cleaning_logs');
    }
};
