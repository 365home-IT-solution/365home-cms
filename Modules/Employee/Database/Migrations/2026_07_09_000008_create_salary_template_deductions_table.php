<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('salary_template_deductions')) {
            return;
        }

        Schema::create('salary_template_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained('salary_templates')->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->constrained('deduction_types')->cascadeOnDelete();
            // null = dùng default_amount của deduction_type, có giá trị = ghi đè riêng cho mẫu lương này
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['salary_template_id', 'deduction_type_id'], 'std_template_deduction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_template_deductions');
    }
};
