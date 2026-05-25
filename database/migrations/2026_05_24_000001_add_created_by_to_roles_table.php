<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'created_by')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('created_by')->nullable()->after('guard_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('roles', 'created_by')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
