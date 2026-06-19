<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cccd_declarations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('full_name')->nullable();
            $table->string('info')->nullable();
            $table->string('cccd_number')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('room_number')->nullable();
            $table->string('reason_for_stay')->nullable();
            $table->string('current_residence')->nullable();
            $table->string('province')->nullable();
            $table->string('ward')->nullable();
            $table->text('address_detail')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cccd_declarations');
    }
};
