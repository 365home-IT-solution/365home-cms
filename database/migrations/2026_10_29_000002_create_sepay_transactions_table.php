<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sepay_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sepay_id')->unique();
            $table->foreignId('partner_sepay_account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('payment_code')->nullable()->index();
            $table->unsignedBigInteger('amount');
            $table->string('reference_code')->nullable();
            $table->timestamp('transaction_at')->nullable();
            $table->string('status', 30)->default('received');
            $table->text('failure_reason')->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sepay_transactions');
    }
};
