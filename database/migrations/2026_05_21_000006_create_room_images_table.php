<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_images', function (Blueprint $table) {
            $table->id();
            $table->char('room_id', 26)->index();
            $table->string('type', 10)->default('gallery'); // main | gallery
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->string('alt', 255)->nullable();
            $table->string('title', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['room_id', 'type']);
            $table->index(['room_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_images');
    }
};
