<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('membership_tier_id')
                ->nullable()
                ->constrained('membership_tiers')
                ->nullOnDelete()
                ->after('token_device');

            $table->decimal('total_spending', 15, 2)->default(0)->after('membership_tier_id');
            $table->timestamp('welcome_coupon_sent_at')->nullable()->after('total_spending');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_tier_id');
            $table->dropColumn(['total_spending', 'welcome_coupon_sent_at']);
        });
    }
};
