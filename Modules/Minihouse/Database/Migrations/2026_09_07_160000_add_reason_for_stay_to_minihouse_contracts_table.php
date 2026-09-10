<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chọn sẵn "Lý do lưu trú" ngay khi tạo hợp đồng — dùng ĐÚNG danh mục ResidenceDeclaration::
// REASON_FOR_STAY_OPTIONS (chuẩn Bộ Công an) để ResidenceDeclarationService tự điền luôn vào Khai
// báo lưu trú sinh ra từ hợp đồng này, không còn để trống bắt nhân viên phải vào từng khai báo bổ
// sung tay (trước đây luôn thiếu, chặn không bấm "Đánh dấu đã khai báo" được).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->string('reason_for_stay')->nullable()->after('status');
            $table->string('custom_reason')->nullable()->after('reason_for_stay');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn(['reason_for_stay', 'custom_reason']);
        });
    }
};
