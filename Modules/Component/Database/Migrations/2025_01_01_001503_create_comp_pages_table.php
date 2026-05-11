<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_pages', function (Blueprint $table) {
            $table->id();
            $table->integer('order');
            $table->unsignedBigInteger('component_id');
            $table->unsignedBigInteger('page_id');
            $table->timestamps();

            $table->index('component_id');
            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_pages');
    }
};
