<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('minihouse_buildings')->cascadeOnDelete();
            $table->string('code');
            $table->decimal('area', 8, 2)->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->string('status')->default('trong'); // trong | dang_thue | bao_tri
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_rooms');
    }
};
