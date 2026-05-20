<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('app_pages')->cascadeOnDelete();
            $table->string('key')->comment('VD: section_deals_may');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->enum('layout', ['horizontal_scroll', 'grid', 'featured'])->default('horizontal_scroll');
            $table->boolean('show_arrow')->default(true);
            $table->string('view_all_url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('filter_config')->nullable()->comment('{"room_type_id":1,"tags":["deal"],"limit":10,"order_by":"latest"}');
            $table->json('badge_config')->nullable()->comment('{"label":"Deal","type":"deal","bg_color":"#FF0000","text_color":"#FFFFFF"}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_sections');
    }
};
