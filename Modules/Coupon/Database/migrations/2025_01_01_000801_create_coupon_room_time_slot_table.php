<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_room_time_slot', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('room_time_slot_id');
            $table->timestamp('created_at')->nullable();

            $table->index('coupon_id');
            $table->index('room_time_slot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_room_time_slot');
    }
};
