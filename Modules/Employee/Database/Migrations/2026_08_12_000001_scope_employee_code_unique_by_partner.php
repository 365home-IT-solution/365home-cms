<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// employee_code được sinh tự động theo Employee::generateEmployeeCode() — đếm SỐ LƯỢNG NHÂN VIÊN
// TRONG PHẠM VI ĐỐI TÁC hiện tại (do global scope BelongsToPartner áp dụng khi tạo trong admin
// panel), nên mỗi đối tác đều bắt đầu từ NV0001. Nhưng ràng buộc unique cũ lại là GLOBAL (toàn bảng,
// không phân biệt đối tác) -> 2 đối tác khác nhau cùng tạo nhân viên đầu tiên đều sinh ra "NV0001",
// đối tác thứ 2 insert bị lỗi 1062 Duplicate entry. Đổi thành unique theo cặp (partner_id,
// employee_code) để khớp đúng với logic sinh mã đang có — mỗi đối tác có dải mã riêng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['employee_code']);
            $table->unique(['partner_id', 'employee_code']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['partner_id', 'employee_code']);
            $table->unique('employee_code');
        });
    }
};
