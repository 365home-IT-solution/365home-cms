<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_branches')) {
            return;
        }

        // Chi nhánh làm việc cụ thể — chỉ có ý nghĩa khi employees.works_all_branches = false
        Schema::create('employee_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'category_id'], 'employee_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branches');
    }
};
