<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nền tảng ký bằng USB token: lưu cách ký và dấu vân tay của file PDF gốc (chưa ký) đã đưa cho người ký, để khi tải bản đã ký lên
// kiểm tra được nội dung hợp đồng không bị thay đổi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->string('platform_sign_method', 20)->nullable()->after('platform_signing_provider');
            $table->string('platform_sign_base_hash', 64)->nullable()->after('platform_sign_method');
            $table->unsignedInteger('platform_sign_base_length')->nullable()->after('platform_sign_base_hash');
            $table->timestamp('platform_sign_prepared_at')->nullable()->after('platform_sign_base_length');
        });
    }

    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropColumn(['platform_sign_method', 'platform_sign_base_hash', 'platform_sign_base_length', 'platform_sign_prepared_at']);
        });
    }
};
