<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_ratings', function (Blueprint $table) {
            $table->id();
            $table->char('customer_id', 36);
            $table->char('room_id', 36);
            $table->unsignedTinyInteger('star');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'room_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('room_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_ratings');
    }
};
