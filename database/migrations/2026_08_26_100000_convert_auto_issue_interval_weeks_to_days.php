<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->unsignedInteger('auto_issue_interval_days')->default(7)->after('auto_issue_interval_weeks');
        });

        DB::table('membership_tiers')->update([
            'auto_issue_interval_days' => DB::raw('auto_issue_interval_weeks * 7'),
        ]);

        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn('auto_issue_interval_weeks');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_issue_interval_weeks')->default(1)->after('auto_issue_enabled');
        });

        DB::table('membership_tiers')->update([
            'auto_issue_interval_weeks' => DB::raw('GREATEST(1, ROUND(auto_issue_interval_days / 7))'),
        ]);

        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn('auto_issue_interval_days');
        });
    }
};
