<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_allowances')) {
            return;
        }

        Schema::create('employee_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('allowance_type_id')->constrained('allowance_types')->cascadeOnDelete();
            // null = dùng default_amount của allowance_type, có giá trị = ghi đè riêng cho nhân viên này
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'allowance_type_id'], 'employee_allowance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_allowances');
    }
};
