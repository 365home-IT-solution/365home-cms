<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('override_room_prices')) {
            return;
        }

        Schema::create('override_room_prices', function (Blueprint $table) {
            $table->id();
            $table->char('room_id', 26);
            $table->date('date')->comment('Ngày áp dụng ghi đè');
            $table->time('checkin')->nullable()->comment('Giờ nhận phòng ghi đè');
            $table->time('checkout')->nullable()->comment('Giờ trả phòng ghi đè');
            $table->unsignedBigInteger('price_override')->nullable()->comment('Giá ghi đè VNĐ');
            $table->timestamps();

            $table->index('room_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('override_room_prices');
    }
};
