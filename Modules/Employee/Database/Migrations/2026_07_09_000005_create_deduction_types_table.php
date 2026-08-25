<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deduction_types')) {
            return;
        }

        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('calc_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_types');
    }
};
