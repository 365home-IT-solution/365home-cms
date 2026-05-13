<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('headers')) {
            return;
        }

        Schema::create('headers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('logo_size');
            $table->string('background_color')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headers');
    }
};
