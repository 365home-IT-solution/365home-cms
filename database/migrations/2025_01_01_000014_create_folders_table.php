<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('collection')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_protected')->default(false);
            $table->string('password')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('has_user_access')->default(false);
            $table->char('user_id', 36)->nullable();
            $table->string('user_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
