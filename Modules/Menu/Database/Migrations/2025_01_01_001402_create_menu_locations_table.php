<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_id');
            $table->string('location');
            $table->timestamps();

            $table->index('menu_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_locations');
    }
};
