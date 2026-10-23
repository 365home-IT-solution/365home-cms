<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Giấy phép kinh doanh thêm ô "Tên doanh nghiệp/hộ kinh doanh" (dkkd_business_name): trang đăng ký hợp tác không còn hỏi tên cơ sở,
// tên pháp lý của đối tác lấy từ giấy phép đã quét (PartnerOnboardingService::applyDocumentToProfile).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('partner_legal_documents', 'dkkd_business_name')) {
            Schema::table('partner_legal_documents', fn (Blueprint $table) => $table->string('dkkd_business_name')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partner_legal_documents', 'dkkd_business_name')) {
            Schema::table('partner_legal_documents', fn (Blueprint $table) => $table->dropColumn('dkkd_business_name'));
        }
    }
};
