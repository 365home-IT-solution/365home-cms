<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('amenity_type');              // e.g. "Phòng tắm", "Tiện nghi"
            $table->string('icon')->nullable();           // icon name, giống room_types
            $table->string('name');                       // e.g. "Vòi sen", "Bồn tắm"
            $table->boolean('status')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_amenities');
    }
};
