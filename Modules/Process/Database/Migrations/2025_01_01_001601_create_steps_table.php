<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('icon')->nullable();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->unsignedBigInteger('process_id');
            $table->timestamps();

            $table->index('process_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps');
    }
};
