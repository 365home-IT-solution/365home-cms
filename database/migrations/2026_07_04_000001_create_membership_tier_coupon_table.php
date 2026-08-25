<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tier_coupon', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('membership_tier_id');
            $table->unsignedBigInteger('coupon_id');
            $table->timestamps();

            $table->unique(['membership_tier_id', 'coupon_id']);
            $table->foreign('membership_tier_id')->references('id')->on('membership_tiers')->cascadeOnDelete();
            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_tier_coupon');
    }
};
