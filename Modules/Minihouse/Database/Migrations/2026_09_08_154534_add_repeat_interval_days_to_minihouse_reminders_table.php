<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->unsignedInteger('repeat_interval_days')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->dropColumn('repeat_interval_days');
        });
    }
};
