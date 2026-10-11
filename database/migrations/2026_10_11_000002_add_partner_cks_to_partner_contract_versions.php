<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đối tác có thể ký hợp đồng bằng chữ ký số của chính họ (CKS USB hoặc từ xa) thay vì OTP email: lưu cách ký và dấu vân tay của file PDF gốc đã đưa cho đối tác ký.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->string('partner_sign_method', 20)->nullable()->after('partner_signing_provider');   // otp | cks
            $table->string('partner_sign_base_hash', 64)->nullable()->after('partner_sign_method');
            $table->unsignedInteger('partner_sign_base_length')->nullable()->after('partner_sign_base_hash');
            $table->timestamp('partner_sign_prepared_at')->nullable()->after('partner_sign_base_length');
        });
    }

    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropColumn(['partner_sign_method', 'partner_sign_base_hash', 'partner_sign_base_length', 'partner_sign_prepared_at']);
        });
    }
};
