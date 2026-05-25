<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_branch_permissions', 'created_by')) {
            return;
        }

        Schema::table('user_branch_permissions', function (Blueprint $table) {
            $table->char('created_by', 36)->nullable()->after('category_id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('user_branch_permissions', 'created_by')) {
            return;
        }

        Schema::table('user_branch_permissions', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
