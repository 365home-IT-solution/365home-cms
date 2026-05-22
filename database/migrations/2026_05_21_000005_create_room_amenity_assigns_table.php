<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_amenity_assigns', function (Blueprint $table) {
            $table->id();
            $table->char('room_id', 26);
            $table->unsignedBigInteger('amenity_id');

            $table->unique(['room_id', 'amenity_id']);
            $table->foreign('amenity_id')->references('id')->on('room_amenities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_amenity_assigns');
    }
};
