<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_checkin_cycles', function (Blueprint $table) {
            $table->id();
            $table->char('customer_id', 36);
            $table->foreignId('membership_tier_id')->constrained('membership_tiers')->cascadeOnDelete();
            $table->unsignedInteger('days_required');
            $table->date('cycle_start_date');
            $table->unsignedInteger('days_checked')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'completed_at']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_checkin_cycles');
    }
};
