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
            $table->boolean('auto_issue_enabled')->default(false)->after('welcome_coupon_usage_limit');
            $table->unsignedTinyInteger('auto_issue_day_of_week')->nullable()->after('auto_issue_enabled');
            $table->time('auto_issue_time')->nullable()->after('auto_issue_day_of_week');
            $table->string('auto_issue_coupon_type')->nullable()->after('auto_issue_time');
            $table->decimal('auto_issue_coupon_value', 12, 2)->nullable()->after('auto_issue_coupon_type');
            $table->unsignedInteger('auto_issue_coupon_days')->nullable()->after('auto_issue_coupon_value');
            $table->unsignedInteger('auto_issue_coupon_usage_limit')->nullable()->after('auto_issue_coupon_days');
            $table->string('auto_issue_notify_title')->nullable()->after('auto_issue_coupon_usage_limit');
            $table->string('auto_issue_notify_body', 500)->nullable()->after('auto_issue_notify_title');
            $table->timestamp('auto_issue_last_run_at')->nullable()->after('auto_issue_notify_body');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'auto_issue_enabled',
                'auto_issue_day_of_week',
                'auto_issue_time',
                'auto_issue_coupon_type',
                'auto_issue_coupon_value',
                'auto_issue_coupon_days',
                'auto_issue_coupon_usage_limit',
                'auto_issue_notify_title',
                'auto_issue_notify_body',
                'auto_issue_last_run_at',
            ]);
        });
    }
};
