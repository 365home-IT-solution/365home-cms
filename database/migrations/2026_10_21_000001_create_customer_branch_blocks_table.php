<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Khoá khách hàng đặt phòng theo chi nhánh (category gốc, category_type=product): mỗi dòng = khách không được
// đặt phòng ở 1 chi nhánh. Kiểm tra ở mọi luồng tạo đơn — xem App\Services\CustomerBranchBlockService.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_branch_blocks')) {
            return;
        }

        Schema::create('customer_branch_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 36);
            $table->unsignedBigInteger('category_id');
            $table->string('reason', 500)->nullable();
            $table->uuid('blocked_by')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'category_id']);
            $table->index('category_id');
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('blocked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_branch_blocks');
    }
};
