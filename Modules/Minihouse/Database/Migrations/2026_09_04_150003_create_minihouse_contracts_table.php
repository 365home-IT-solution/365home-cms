<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('minihouse_rooms')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('minihouse_tenants')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('monthly_price', 14, 2);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->string('status')->default('active'); // active | expired | cancelled
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contracts');
    }
};
