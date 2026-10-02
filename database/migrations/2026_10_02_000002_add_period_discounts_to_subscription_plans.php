<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ưu đãi theo kỳ mua: {"1":0,"3":0,"6":0,"9":0,"12":0} = % giảm của từng kỳ (để trống = không giảm, điền sau).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscription_plans', 'period_discounts')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->json('period_discounts')->nullable()->after('trial_months');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscription_plans', 'period_discounts')) {
            Schema::table('subscription_plans', function (Blueprint $table) {
                $table->dropColumn('period_discounts');
            });
        }
    }
};
