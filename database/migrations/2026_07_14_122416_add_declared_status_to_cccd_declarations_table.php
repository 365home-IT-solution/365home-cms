<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            // Đánh dấu NHÂN VIÊN đã thật sự nộp khai báo lưu trú qua ASM/dịch vụ công (bên ngoài
            // hệ thống này) hay chưa — dữ liệu ở bảng này chỉ là tài liệu tham chiếu nội bộ, KHÔNG
            // tự động gửi đi cho cơ quan công an, nên cần 1 cách để nhân viên tự xác nhận đã nộp
            // thủ công, tránh quên dẫn tới bị phạt (2-4 triệu/lần theo quy định từ 01/07/2026).
            $table->dateTime('declared_at')->nullable()->after('address_detail');
            // users.id là UUID (char 36), không phải bigint — khớp đúng kiểu cột đã dùng ở nơi
            // khác trong dự án (vd partner_contract_versions.platform_signed_by).
            $table->char('declared_by', 36)->nullable()->after('declared_at');
            $table->foreign('declared_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->dropForeign(['declared_by']);
            $table->dropColumn(['declared_at', 'declared_by']);
        });
    }
};
