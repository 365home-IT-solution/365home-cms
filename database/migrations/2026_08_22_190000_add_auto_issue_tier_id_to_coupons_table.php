<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('auto_issue_tier_id')->nullable()->after('template_coupon_id')
                ->constrained('membership_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropForeign(['auto_issue_tier_id']);
            $table->dropColumn('auto_issue_tier_id');
        });
    }
};
