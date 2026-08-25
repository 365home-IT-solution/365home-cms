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
        // Tách "đối tác xác nhận đồng ý" (qua OTP email, có thể xảy ra BẤT KỲ lúc nào, không có
        // nhân viên nào đứng cạnh để nhập OTP VNPT SmartCA) khỏi "đã áp CHỮ KÝ SỐ THẬT"
        // (partner_signed_at — giờ chỉ được set khi nhân viên thực hiện bước riêng, nhập OTP tay).
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->timestamp('partner_confirmed_at')->nullable()->after('signing_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropColumn('partner_confirmed_at');
        });
    }
};
