<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_sepay_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->unique();
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('bank_code', 20);
            $table->string('bank_bin', 12);
            $table->string('account_number', 50)->unique();
            $table->string('account_holder');
            $table->string('note')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_sepay_accounts');
    }
};
