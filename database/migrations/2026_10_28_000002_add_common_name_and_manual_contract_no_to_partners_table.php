<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hai ô NHẬP TAY ở hồ sơ đối tác để tra cứu nhanh trong danh sách:
 *  - common_name: tên thường gọi (vd "RinBon Home") — khác tên pháp lý (legal_name / name).
 *  - manual_contract_no: số hợp đồng gõ tay — KHÔNG liên quan gì tới contract_code (mã hợp đồng do hệ thống cấp và in lên hợp đồng).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'common_name')) {
                $table->string('common_name')->nullable();
            }

            if (! Schema::hasColumn('partners', 'manual_contract_no')) {
                $table->string('manual_contract_no', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            foreach (['common_name', 'manual_contract_no'] as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
