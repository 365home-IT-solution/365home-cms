<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_media')) {
            return;
        }

        Schema::create('social_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('footer_column_id');
            $table->string('platform');
            $table->string('icon')->nullable();
            $table->string('url');
            $table->timestamps();

            $table->index('footer_column_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media');
    }
};
