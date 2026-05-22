<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_specials', function (Blueprint $table) {
            $table->id();
            $table->char('product_id', 26);
            $table->string('icon')->nullable();           // icon name
            $table->string('title');                      // e.g. "Miễn phí WiFi"
            $table->text('short_description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_specials');
    }
};
