<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('promotions', 'created_by')) {
            return;
        }

        Schema::table('promotions', function (Blueprint $table) {
            $table->char('created_by', 36)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('promotions', 'created_by')) {
            return;
        }

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
