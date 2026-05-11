<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code')->nullable();
            $table->integer('amount')->nullable();
            $table->unsignedBigInteger('full_amount')->nullable()->comment('Tổng tiền đặt phòng thực tế. amount = tiền cọc cần thanh toán.');
            $table->unsignedTinyInteger('deposit_percent')->nullable()->comment('% cọc đã áp dụng. NULL = thanh toán đủ 100%.');
            $table->timestamp('deposit_paid_at')->nullable();
            $table->timestamp('remaining_paid_at')->nullable();
            $table->bigInteger('remaining_payos_code')->nullable();
            $table->string('remaining_checkout_url')->nullable();
            $table->string('deposit_retry_payos_code')->nullable();
            $table->text('description')->nullable();
            $table->longText('items')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->string('buyer_address')->nullable();
            $table->string('ward_name')->nullable();
            $table->string('district_name')->nullable();
            $table->string('province_name')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('shipping_method')->nullable();
            $table->integer('is_freeship')->default(0);
            $table->timestamp('expired_at')->nullable();
            $table->string('checkout_url')->nullable();
            $table->enum('status', ['pending', 'paid', 'failed', 'shipped', 'deposit'])->default('pending');
            $table->text('cccd_front')->nullable();
            $table->text('cccd_back')->nullable();
            $table->longText('cccd_data')->nullable();
            $table->integer('guest_count')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->char('user_id', 36)->nullable();
            $table->text('note_for_admin')->nullable();
            $table->text('deposit_room')->nullable();
            $table->unsignedInteger('money_deposit')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
