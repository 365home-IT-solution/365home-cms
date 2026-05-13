<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categorizables')) {
            return;
        }

        Schema::create('categorizables', function (Blueprint $table) {
            $table->id();
            $table->string('categorizable_type');
            $table->char('categorizable_id', 36);
            $table->unsignedBigInteger('category_id');
            $table->timestamps();

            $table->index(['categorizable_type', 'categorizable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorizables');
    }
};
