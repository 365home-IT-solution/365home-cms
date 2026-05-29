<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_lock_passwords', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->comment('Tên nhóm/ghi chú cho bộ mật khẩu này');
            $table->string('gate_password')->comment('Mật khẩu cổng (Pass Cổng)');
            $table->string('room_password')->nullable()->comment('Mật khẩu phòng (Pass Phòng)');
            $table->unsignedBigInteger('category_id')->nullable()->comment('Chi nhánh');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('manual_lock_password_product', function (Blueprint $table) {
            $table->unsignedBigInteger('manual_lock_password_id');
            $table->char('product_id', 36);

            $table->primary(['manual_lock_password_id', 'product_id']);

            $table->foreign('manual_lock_password_id')
                ->references('id')
                ->on('manual_lock_passwords')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_lock_password_product');
        Schema::dropIfExists('manual_lock_passwords');
    }
};
