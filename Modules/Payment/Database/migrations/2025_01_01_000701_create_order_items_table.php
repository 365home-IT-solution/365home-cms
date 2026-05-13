<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items')) {
            return;
        }

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->char('product_id', 36)->nullable();
            $table->string('name');
            $table->integer('price')->nullable();
            $table->integer('discount')->nullable();
            $table->integer('vat')->nullable();
            $table->integer('quantity')->nullable();
            $table->boolean('is_shipped')->default(true);
            $table->dateTime('checkin_date')->nullable();
            $table->dateTime('checkout_date')->nullable();
            $table->string('slot_label')->nullable();
            $table->integer('slot_day')->nullable();
            $table->integer('slot_raw_day')->nullable();
            $table->string('extra_fee')->nullable();
            $table->integer('guest_count')->nullable();
            $table->dateTime('checkin_datetime')->nullable();
            $table->dateTime('checkout_datetime')->nullable();
            $table->boolean('expiry_notified')->default(false)->comment('Đã gửi Telegram cảnh báo 15 phút trước checkout');
            $table->boolean('checkout_notified')->default(false)->comment('Đã gửi Telegram thông báo hết giờ checkout');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
