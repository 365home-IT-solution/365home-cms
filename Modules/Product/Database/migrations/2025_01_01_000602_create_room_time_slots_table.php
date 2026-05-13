<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_time_slots')) {
            return;
        }

        Schema::create('room_time_slots', function (Blueprint $table) {
            $table->id();
            $table->char('room_id', 36);
            $table->unsignedBigInteger('timeslot_id');
            $table->date('date')->nullable();
            $table->integer('price')->nullable();
            $table->time('checkin')->nullable();
            $table->time('checkout')->nullable();
            $table->enum('status', ['available', 'booked', 'selected', 'promotion', 'blind_bag'])->default('available');
            $table->boolean('over_night')->nullable();
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->index('room_id');
            $table->index('timeslot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_time_slots');
    }
};
