<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Một số vị trí (vd Lễ tân) không hưởng lương cố định mà tính theo lượt công việc (vd 10.000đ/lượt
// dọn phòng, 5.000đ/lượt tư vấn khách hàng). 'calc_type' đánh dấu loại lương nào tính theo lượt
// (fixed = lương cơ bản cố định như trước giờ, piece_rate = tính theo số lượt công việc thực tế
// trong tháng, xem Employee::getFinalSalaryAttribute()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_types', function (Blueprint $table) {
            $table->string('calc_type')->default('fixed')->after('slug');
            $table->decimal('room_cleaning_rate', 15, 2)->nullable()->after('calc_type');
            $table->decimal('consultation_rate', 15, 2)->nullable()->after('room_cleaning_rate');
        });
    }

    public function down(): void
    {
        Schema::table('salary_types', function (Blueprint $table) {
            $table->dropColumn(['calc_type', 'room_cleaning_rate', 'consultation_rate']);
        });
    }
};
