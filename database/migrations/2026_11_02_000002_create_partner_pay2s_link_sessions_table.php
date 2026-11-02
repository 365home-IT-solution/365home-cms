<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_pay2s_link_sessions')) {
            return;
        }

        Schema::create('partner_pay2s_link_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->string('session_id', 100)->unique();
            $table->string('customer_reference', 128);
            $table->string('status', 30)->default('created');
            $table->text('hosted_link_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message')->nullable();
            $table->boolean('applied')->default(false);
            $table->json('account')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_pay2s_link_sessions');
    }
};
