<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_board_items')) {
            return;
        }

        Schema::create('price_board_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_board_id')->constrained('price_boards')->cascadeOnDelete();
            $table->char('product_id', 36);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->decimal('price', 15, 2)->nullable();
            $table->string('price_unit')->nullable();
            $table->string('full_booking_discount', 50)->nullable();
            $table->longText('bulk_discount_rules')->nullable();
            $table->longText('room_config')->nullable();
            $table->unsignedTinyInteger('deposit_1_night')->nullable();
            $table->unsignedTinyInteger('deposit_multi_night')->nullable();
            $table->unsignedTinyInteger('deposit_min_nights')->nullable();
            $table->time('default_checkin')->nullable();
            $table->time('default_checkout')->nullable();
            $table->timestamps();

            $table->unique(['price_board_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_board_items');
    }
};
