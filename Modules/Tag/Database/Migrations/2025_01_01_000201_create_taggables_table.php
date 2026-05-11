<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taggables', function (Blueprint $table) {
            $table->string('taggable_type');
            $table->char('taggable_id', 26);
            $table->unsignedBigInteger('tag_id');

            $table->primary(['taggable_type', 'taggable_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
    }
};
