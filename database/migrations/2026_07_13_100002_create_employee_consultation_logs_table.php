<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ghi nhận mỗi lượt lễ tân/nhân viên tư vấn khách hàng — dùng để tính lương theo lượt (xem
// migration add_piece_rate_fields_to_salary_types_table + Employee::getFinalSalaryAttribute()).
// Lượt dọn phòng đã có sẵn ở bảng room_cleaning_logs (employee_id + cleaned_at), không cần bảng
// riêng; nhưng "tư vấn khách hàng" trước giờ chưa được ghi nhận ở đâu cả nên phải tạo bảng mới.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_consultation_logs')) {
            return;
        }

        Schema::create('employee_consultation_logs', function (Blueprint $table) {
            $table->id();

            $table->uuid('partner_id')->nullable();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('consulted_at');

            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_consultation_logs');
    }
};
