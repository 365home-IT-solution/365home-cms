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
            $table->decimal('auto_issue_coupon_value_max', 12, 2)->nullable()->after('auto_issue_coupon_value');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn('auto_issue_coupon_value_max');
        });
    }
};
