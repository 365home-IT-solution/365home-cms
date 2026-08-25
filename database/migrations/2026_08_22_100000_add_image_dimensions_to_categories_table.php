<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'image_width')) {
                $table->unsignedInteger('image_width')->nullable()->after('image');
            }
            if (! Schema::hasColumn('categories', 'image_height')) {
                $table->unsignedInteger('image_height')->nullable()->after('image_width');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'image_height')) {
                $table->dropColumn('image_height');
            }
            if (Schema::hasColumn('categories', 'image_width')) {
                $table->dropColumn('image_width');
            }
        });
    }
};
