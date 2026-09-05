<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->nullable()->constrained('minihouse_contracts')->nullOnDelete();
            $table->string('type'); // thu | chi
            $table->decimal('amount', 14, 2);
            $table->date('transaction_date');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_transactions');
    }
};
