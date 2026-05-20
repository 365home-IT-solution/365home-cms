<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('thumbnail_color', 20)->nullable()->after('name');
            $table->string('price_unit', 20)->nullable()->after('price')->comment('per_hour | per_night | per_day');
            $table->decimal('rating_score', 3, 1)->nullable()->after('price_unit');
            $table->json('badge')->nullable()->after('rating_score')->comment('{"label":"...","type":"...","bg_color":"#FFF","text_color":"#000"}');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_color', 'price_unit', 'rating_score', 'badge']);
        });
    }
};
