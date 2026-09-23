<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mẫu "HỢP ĐỒNG THUÊ PHÒNG TRỌ" chuẩn cần dòng "CMND/CCCD số ... cấp ngày ... nơi cấp ..." — trước
// đây Tenant chỉ có id_card_number, thiếu 2 field này nên dòng đó luôn để trống trên hợp đồng in.
// Dùng cho ContractDocumentService khi dựng bản hợp đồng điện tử (xem docs/be-minihouse-contract-
// signing.md mục 12), nhân viên điền qua PATCH .../document hoặc PATCH .../tenants/{id} như nhau.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->date('id_card_issued_date')->nullable()->after('id_card_number');
            $table->string('id_card_issued_place')->nullable()->after('id_card_issued_date');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn(['id_card_issued_date', 'id_card_issued_place']);
        });
    }
};
