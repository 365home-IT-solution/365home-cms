<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'area_sqm')) {
                $table->decimal('area_sqm', 10, 2)->nullable()->after('description');
            }
            if (! Schema::hasColumn('categories', 'established_year')) {
                $table->unsignedSmallInteger('established_year')->nullable()->after('area_sqm');
            }
            if (! Schema::hasColumn('categories', 'lodging_type')) {
                $table->string('lodging_type')->nullable()->after('established_year');
            }
            if (! Schema::hasColumn('categories', 'timezone')) {
                $table->string('timezone', 50)->default('Asia/Ho_Chi_Minh')->after('lodging_type');
            }
            if (! Schema::hasColumn('categories', 'operation_manager_name')) {
                $table->string('operation_manager_name')->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('categories', 'checkin_time')) {
                $table->time('checkin_time')->nullable()->after('operation_manager_name');
            }
            if (! Schema::hasColumn('categories', 'checkout_time')) {
                $table->time('checkout_time')->nullable()->after('checkin_time');
            }
            if (! Schema::hasColumn('categories', 'default_policy')) {
                $table->text('default_policy')->nullable()->after('checkout_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            foreach ([
                'area_sqm', 'established_year', 'lodging_type', 'timezone',
                'operation_manager_name', 'checkin_time', 'checkout_time', 'default_policy',
            ] as $column) {
                if (Schema::hasColumn('categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
