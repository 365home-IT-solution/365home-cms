<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('electric_amount', 14, 2)->default(0);
            $table->decimal('water_amount', 14, 2)->default(0);
            $table->decimal('service_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status')->default('unpaid'); // unpaid | paid
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_invoices');
    }
};
