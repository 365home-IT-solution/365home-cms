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
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            // Chữ ký số THẬT (không phải chỉ OTP+click như trước) — ký trên content_hash bằng
            // private key của provider tương ứng, verify được bằng certificate đi kèm. Provider
            // lưu RIÊNG cho mỗi bên (không dùng chung 1 cột) vì 2 bên có thể ký ở 2 THỜI ĐIỂM
            // khác nhau — nếu đổi CONTRACT_SIGNING_PROVIDER giữa lúc đối tác ký và lúc nền tảng ký
            // (vd đang test 'local' rồi chuyển sang 'vnpt_smartca' thật), verify lại chữ ký cũ vẫn
            // phải dùng ĐÚNG provider đã ký nó, không phải provider hiện đang cấu hình.
            $table->string('partner_signing_provider')->nullable()->after('content_hash');
            $table->longText('partner_signature')->nullable()->after('partner_signed_user_agent');
            $table->json('partner_signature_certificate')->nullable()->after('partner_signature');

            $table->string('platform_signing_provider')->nullable()->after('partner_signature_certificate');
            $table->longText('platform_signature')->nullable()->after('platform_signed_user_agent');
            $table->json('platform_signature_certificate')->nullable()->after('platform_signature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropColumn([
                'partner_signing_provider',
                'partner_signature',
                'partner_signature_certificate',
                'platform_signing_provider',
                'platform_signature',
                'platform_signature_certificate',
            ]);
        });
    }
};
