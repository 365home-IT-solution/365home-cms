<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->comment('Mã mở cửa');
            $table->unsignedBigInteger('category_id');
            $table->enum('status', ['active', 'disabled', 'expired'])->default('active');
            $table->integer('usage_count')->default(0);
            $table->timestamp('used_at')->nullable()->comment('Thời gian sử dụng');
            $table->timestamp('valid_from')->nullable()->comment('Có hiệu lực từ');
            $table->timestamp('valid_until')->nullable()->comment('Hết hiệu lực');
            $table->string('gate_location')->nullable()->comment('Vị trí cổng cụ thể');
            $table->unsignedBigInteger('ttlock_keyboard_pwd_id')->nullable()->comment('TTLock keyboardPwdId của khóa checkin');
            $table->unsignedBigInteger('ttlock_keyboard_pwd_id_checkout')->nullable()->comment('TTLock keyboardPwdId của khóa checkout');
            $table->string('max_uses', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('category_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_codes');
    }
};
