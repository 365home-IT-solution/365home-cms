<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable();
            $table->string('type')->default('simple');
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->decimal('price', 15, 2)->default(0.00);
            $table->decimal('discount', 15, 2)->default(0.00);
            $table->string('wifi')->nullable();
            $table->string('home_code')->nullable();
            $table->unsignedBigInteger('lock_id')->nullable()->comment('TTLock lockId - ID khóa thông minh TTLock');
            $table->unsignedBigInteger('lock_id_checkout')->nullable()->comment('TTLock ID của khóa trong (dùng để check-out)');
            $table->string('address')->nullable();
            $table->string('hotline')->nullable();
            $table->decimal('vat', 5, 2)->default(0.00);
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->boolean('is_in_stock')->default(true);
            $table->boolean('is_activated')->default(true);
            $table->boolean('is_shipped')->default(true);
            $table->boolean('is_trend')->default(false);
            $table->string('full_booking_discount', 50)->nullable();
            $table->time('default_checkin')->nullable();
            $table->time('default_checkout')->nullable();
            $table->unsignedTinyInteger('deposit_1_night')->default(100)->comment('Phần trăm cọc khi đặt 1 đêm (0-100). Mặc định 100 = thanh toán đủ.');
            $table->unsignedTinyInteger('deposit_multi_night')->default(50)->comment('Phần trăm cọc khi đặt từ 2 đêm trở lên (0-100). Mặc định 50.');
            $table->unsignedTinyInteger('deposit_min_nights')->default(2);
            $table->longText('room_config')->nullable();
            $table->tinyInteger('styles')->default(1);
            $table->text('setting_video_room')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
