<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chặn SĐT đặt phòng theo chi nhánh — SĐT không cần có hồ sơ khách hàng. Lưu dạng chuẩn hoá 0xxxxxxxxx (xem
// CustomerBranchBlockService::normalizePhone()). Kiểm tra cùng chỗ với customer_branch_blocks.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phone_branch_blocks')) {
            return;
        }

        Schema::create('phone_branch_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->unsignedBigInteger('category_id');
            $table->string('reason', 500)->nullable();
            $table->uuid('blocked_by')->nullable();
            $table->timestamps();

            $table->unique(['phone', 'category_id']);
            $table->index('category_id');
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('blocked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_branch_blocks');
    }
};
