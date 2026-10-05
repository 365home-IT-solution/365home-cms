<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Trường RIÊNG theo loại giấy tờ (ĐKKD: địa chỉ kinh doanh, ngành nghề, người đại diện...; ANTT: tên cơ sở, người chịu trách nhiệm...;
// PCCC: chủ đầu tư, người đại diện, chức danh, địa điểm...). Số / nơi cấp / ngày cấp vẫn dùng các cột chung sẵn có.
// Danh sách trường theo loại: App\Support\LegalDocumentFields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('partner_legal_documents', 'extra')) {
                $table->json('extra')->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            if (Schema::hasColumn('partner_legal_documents', 'extra')) {
                $table->dropColumn('extra');
            }
        });
    }
};
