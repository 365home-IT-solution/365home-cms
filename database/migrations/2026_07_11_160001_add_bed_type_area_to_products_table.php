<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'bed_type')) {
                $table->string('bed_type')->nullable()->after('type');
            }
            if (! Schema::hasColumn('products', 'room_area_sqm')) {
                $table->decimal('room_area_sqm', 8, 2)->nullable()->after('bed_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['bed_type', 'room_area_sqm'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
