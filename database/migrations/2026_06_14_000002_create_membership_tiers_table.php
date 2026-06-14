<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('color', 20)->default('#6B7280');
            $table->string('icon', 50)->default('heroicon-o-star');
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('min_spending', 15, 2)->default(0);
            $table->string('welcome_coupon_prefix', 20)->nullable();
            $table->enum('welcome_coupon_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('welcome_coupon_value', 10, 2)->default(0);
            $table->unsignedInteger('welcome_coupon_days', )->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_tiers');
    }
};
