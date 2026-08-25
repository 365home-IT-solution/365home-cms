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
        // Các trường còn thiếu so với đúng mẫu chính thức "Thông báo lưu trú" của Bộ Công an
        // (tblt_vn_import.xlsx) — lưu SẴN đúng định dạng "Mã - Tên" hiển thị của mẫu (vd
        // "M - Nam", "VNM - Viet Nam", "1 - Thẻ CCCD", "1 - Du lịch") để xuất Excel không cần
        // chuyển đổi lại, và khớp thẳng với dropdown "chọn theo Sheet [DANH_MUC]" của mẫu.
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->string('gender')->nullable()->after('date_of_birth');
            $table->string('nationality')->nullable()->after('cccd_number');
            $table->string('document_type')->nullable()->after('nationality');
            $table->string('phone_number')->nullable()->after('document_type');
            // "Nơi cư trú hiện nay" theo mẫu là PHÂN LOẠI (Thường trú/Tạm trú/Khác) của địa chỉ ở
            // current_residence — KHÁC với chính current_residence (giá trị/nội dung địa chỉ).
            $table->string('residence_type')->nullable()->after('current_residence');
            // Chỉ bắt buộc nhập khi reason_for_stay = "20 - Mục đích khác".
            $table->string('custom_reason')->nullable()->after('reason_for_stay');
            $table->string('notes')->nullable()->after('address_detail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->dropColumn(['gender', 'nationality', 'document_type', 'phone_number', 'residence_type', 'custom_reason', 'notes']);
        });
    }
};
