<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_amenity', function (Blueprint $table) {
            $table->char('product_id', 26);
            $table->unsignedBigInteger('amenity_id');

            $table->primary(['product_id', 'amenity_id']);
            $table->foreign('amenity_id')->references('id')->on('room_amenities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_amenity');
    }
};
