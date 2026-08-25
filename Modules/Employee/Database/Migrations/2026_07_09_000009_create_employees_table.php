<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees')) {
            return;
        }

        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Thông tin cơ bản
            $table->string('employee_code')->unique();
            $table->string('attendance_code')->nullable()->unique(); // Mã chấm công (máy chấm công/vân tay/thẻ)
            $table->string('name');
            $table->string('phone')->nullable();

            // Chi nhánh
            $table->foreignId('pay_branch_id')->nullable()->constrained('categories')->nullOnDelete(); // Chi nhánh trả lương
            $table->boolean('works_all_branches')->default(false); // true = làm việc tại tất cả chi nhánh

            // Thông tin công việc
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->uuid('user_id')->nullable(); // Tài khoản đăng nhập (App\Models\User có sẵn)
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->date('hire_date')->nullable();
            $table->text('note')->nullable();

            // Thông tin cá nhân
            $table->string('id_number')->nullable(); // Số CMND/CCCD
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();

            // Thông tin liên hệ
            $table->string('address')->nullable();
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->unsignedInteger('ward_code')->nullable();
            $table->string('email')->nullable();
            $table->string('facebook_url')->nullable();

            // Thiết lập lương
            $table->foreignId('salary_type_id')->nullable()->constrained('salary_types')->nullOnDelete();
            $table->foreignId('salary_template_id')->nullable()->constrained('salary_templates')->nullOnDelete();
            $table->decimal('base_amount', 15, 2)->nullable(); // Lương cơ bản riêng (nếu không dùng mẫu, hoặc ghi đè mẫu)
            $table->boolean('has_allowances')->default(false);
            $table->boolean('has_deductions')->default(false);

            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index('ward_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
