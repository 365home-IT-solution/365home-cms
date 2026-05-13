<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('footers')) {
            return;
        }

        Schema::create('footers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(false);
            $table->string('title_color')->nullable();
            $table->string('background_color')->nullable();
            $table->string('base_color')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footers');
    }
};
