<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('footer_columns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('footer_id')->nullable();
            $table->string('title');
            $table->enum('content_type', ['text', 'image', 'iframe', 'social_media', 'google_map', 'menu', 'business']);
            $table->text('text_content')->nullable();
            $table->string('image_content')->nullable();
            $table->text('iframe_content')->nullable();
            $table->text('google_map')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->integer('order');
            $table->timestamps();

            $table->index('footer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('footer_columns');
    }
};
