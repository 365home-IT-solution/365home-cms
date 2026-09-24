<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Khách thuê tự gửi "muốn gia hạn" / "muốn trả phòng" từ Portal (web/API) — CHỈ LÀ 1 CỜ ĐÁNH DẤU +
// ghi chú để NHÂN VIÊN thấy và tự liên hệ xử lý (gọi ContractController::renew()/checkout() như
// bình thường), KHÔNG tự động gia hạn/thanh lý hợp đồng nào — mọi thay đổi thật lên Hợp đồng vẫn do
// nhân viên chủ động thao tác, tránh khách tự ý đổi ngày/giá thuê qua 1 request tự động không ai
// duyệt. *_requested_at rỗng lại (set null) khi nhân viên xử lý xong (renew()/checkout() thật) hoặc
// khi khách rút lại yêu cầu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->timestamp('renewal_requested_at')->nullable()->after('deposit_deduction_reason');
            $table->string('renewal_request_note')->nullable()->after('renewal_requested_at');
            $table->timestamp('checkout_requested_at')->nullable()->after('renewal_request_note');
            $table->string('checkout_request_note')->nullable()->after('checkout_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn(['renewal_requested_at', 'renewal_request_note', 'checkout_requested_at', 'checkout_request_note']);
        });
    }
};
