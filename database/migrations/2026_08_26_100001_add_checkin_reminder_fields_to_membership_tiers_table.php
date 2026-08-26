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
            $table->boolean('checkin_reminder_enabled')->default(false)->after('auto_issue_notify_body');
            $table->json('checkin_reminder_times')->nullable()->after('checkin_reminder_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn(['checkin_reminder_enabled', 'checkin_reminder_times']);
        });
    }
};
