<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();       // e.g. "theo_gio"
            $table->string('name');                 // e.g. "Theo giờ"
            $table->string('icon')->nullable();     // Material Icon name
            $table->string('icon_url')->nullable(); // fallback SVG URL
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
