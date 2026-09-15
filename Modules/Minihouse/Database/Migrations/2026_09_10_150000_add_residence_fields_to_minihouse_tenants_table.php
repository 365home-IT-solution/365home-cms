<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thêm để hồ sơ Khách thuê (kể cả tạo nhanh qua popup ở form Hợp đồng) có chỗ lưu quốc tịch/loại
// giấy tờ — 2 trường ResidenceDeclarationService::upsertFromTenant() SẼ ưu tiên đọc từ đây trước khi
// rơi về mặc định "VNM - Viet Nam"/"1 - Thẻ CCCD" khi tự đồng bộ sang "Khai báo lưu trú" mỗi lúc tạo/
// sửa Hợp đồng — cần thiết cho khách nước ngoài/dùng hộ chiếu, mặc định vẫn đúng cho đa số khách
// trong nước nên KHÔNG bắt buộc nhập. KHÔNG thêm province/ward ở đây — 2 trường đó của bản khai là
// "nơi khách ĐANG lưu trú" (lấy từ Toà nhà đang ở), không phải thuộc tính riêng của khách.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->string('nationality')->nullable()->after('gender');
            $table->string('document_type')->nullable()->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn(['nationality', 'document_type']);
        });
    }
};
