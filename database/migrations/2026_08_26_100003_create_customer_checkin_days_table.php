<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_checkin_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_checkin_cycle_id')->constrained('customer_checkin_cycles')->cascadeOnDelete();
            $table->char('customer_id', 36);
            $table->date('checkin_date');
            $table->enum('source', ['app', 'admin'])->default('app');
            $table->timestamps();

            $table->unique(['customer_id', 'checkin_date']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_checkin_days');
    }
};
