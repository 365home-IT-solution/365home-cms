<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Đề xuất trừ ký quỹ ghi rõ thuộc TRƯỜNG HỢP NÀO trong phụ lục hợp đồng (mục 4 của mô tả nghiệp vụ) — xem
    // App\Models\PartnerEscrowDeduction::CASES.
    public function up(): void
    {
        if (! Schema::hasColumn('partner_escrow_deductions', 'case_code')) {
            Schema::table('partner_escrow_deductions', function (Blueprint $table) {
                $table->string('case_code', 30)->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partner_escrow_deductions', 'case_code')) {
            Schema::table('partner_escrow_deductions', fn (Blueprint $table) => $table->dropColumn('case_code'));
        }
    }
};
