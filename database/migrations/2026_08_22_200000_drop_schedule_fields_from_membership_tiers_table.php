<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn(['auto_issue_day_of_week', 'auto_issue_time', 'auto_issue_last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_issue_day_of_week')->nullable()->after('auto_issue_interval_weeks');
            $table->time('auto_issue_time')->nullable()->after('auto_issue_day_of_week');
            $table->timestamp('auto_issue_last_run_at')->nullable()->after('auto_issue_notify_body');
        });
    }
};
