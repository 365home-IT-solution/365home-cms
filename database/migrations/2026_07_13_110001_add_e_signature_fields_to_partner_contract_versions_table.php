<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ký điện tử TỰ XÂY (không qua nhà cung cấp chữ ký số bên thứ 3): mỗi phiên bản hợp đồng có 1
// bản nội dung CỐ ĐỊNH tại thời điểm tạo (content + content_hash để đối chiếu toàn vẹn), 1
// link ký công khai (signing_token — đối tác KHÔNG cần tài khoản CMS, chỉ cần mở link + xác
// thực bằng OTP gửi email), và lưu đầy đủ bằng chứng của TỪNG bên ký (thời điểm, tên người ký,
// IP, user agent) — hợp đồng chỉ 'active' khi CẢ 2 bên đã ký (xem PartnerContractVersion::isFullySigned()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->longText('content')->nullable()->after('change_note');
            $table->string('content_hash', 64)->nullable()->after('content');
            $table->string('signing_token', 64)->nullable()->unique()->after('content_hash');

            $table->dateTime('partner_signed_at')->nullable()->after('signing_token');
            $table->string('partner_signed_by_name')->nullable()->after('partner_signed_at');
            $table->string('partner_signed_ip', 45)->nullable()->after('partner_signed_by_name');
            $table->string('partner_signed_user_agent')->nullable()->after('partner_signed_ip');

            $table->dateTime('platform_signed_at')->nullable()->after('partner_signed_user_agent');
            $table->char('platform_signed_by', 36)->nullable()->after('platform_signed_at');
            $table->foreign('platform_signed_by')->references('id')->on('users')->nullOnDelete();
            $table->string('platform_signed_ip', 45)->nullable()->after('platform_signed_by');
            $table->string('platform_signed_user_agent')->nullable()->after('platform_signed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('partner_contract_versions', function (Blueprint $table) {
            $table->dropForeign(['platform_signed_by']);
            $table->dropColumn([
                'content',
                'content_hash',
                'signing_token',
                'partner_signed_at',
                'partner_signed_by_name',
                'partner_signed_ip',
                'partner_signed_user_agent',
                'platform_signed_at',
                'platform_signed_by',
                'platform_signed_ip',
                'platform_signed_user_agent',
            ]);
        });
    }
};
