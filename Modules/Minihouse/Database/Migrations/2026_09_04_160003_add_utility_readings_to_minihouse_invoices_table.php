<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->decimal('room_price', 14, 2)->default(0)->after('month');
            $table->decimal('electric_start', 10, 2)->nullable()->after('electric_amount');
            $table->decimal('electric_end', 10, 2)->nullable()->after('electric_start');
            $table->decimal('electric_unit_price', 14, 2)->nullable()->after('electric_end');
            $table->decimal('water_start', 10, 2)->nullable()->after('water_amount');
            $table->decimal('water_end', 10, 2)->nullable()->after('water_start');
            $table->decimal('water_unit_price', 14, 2)->nullable()->after('water_end');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'room_price',
                'electric_start', 'electric_end', 'electric_unit_price',
                'water_start', 'water_end', 'water_unit_price',
            ]);
        });
    }
};
