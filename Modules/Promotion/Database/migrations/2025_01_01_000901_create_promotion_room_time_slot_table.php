<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promotion_room_time_slot')) {
            return;
        }

        Schema::create('promotion_room_time_slot', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('room_time_slot_id');
            $table->integer('min_quantity')->default(1);
            $table->decimal('custom_value', 10, 2)->nullable();
            $table->timestamps();

            $table->index('promotion_id');
            $table->index('room_time_slot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_room_time_slot');
    }
};
