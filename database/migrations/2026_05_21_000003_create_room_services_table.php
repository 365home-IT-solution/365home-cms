<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_services', function (Blueprint $table) {
            $table->id();
            $table->char('product_id', 26);
            $table->string('name');                  // e.g. "Coffee"
            $table->text('description')->nullable(); // mô tả thêm
            $table->unsignedInteger('price')->default(0); // e.g. 90000
            $table->string('unit')->nullable();      // e.g. "người/ngày", "lần"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_services');
    }
};
