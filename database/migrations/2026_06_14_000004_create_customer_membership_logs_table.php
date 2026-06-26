<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_membership_logs', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id');
            $table->foreignId('from_tier_id')->nullable()->constrained('membership_tiers')->nullOnDelete();
            $table->foreignId('to_tier_id')->constrained('membership_tiers')->cascadeOnDelete();
            $table->enum('reason', ['registration', 'spending_upgrade', 'manual'])->default('spending_upgrade');
            $table->decimal('spending_at_change', 15, 2)->default(0);
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_logs');
    }
};
