<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_fields')) {
            return;
        }

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('label');
            $table->string('name');
            $table->text('options')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('min_length')->nullable();
            $table->integer('max_length')->nullable();
            $table->unsignedBigInteger('form_id');
            $table->timestamps();

            $table->index('form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
