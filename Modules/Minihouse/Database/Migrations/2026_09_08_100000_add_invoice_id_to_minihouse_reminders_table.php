<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Liên kết TUỲ CHỌN tới đúng 1 Hoá đơn cụ thể — chỉ dùng cho nhắc việc loại "Nhắc đóng tiền"
// (Reminder::TYPE_PAYMENT). Trước đây chỉ gắn được contract_id (hợp đồng), không đủ để biết ĐÚNG
// hoá đơn nào đang nhắc khi 1 hợp đồng có nhiều hoá đơn — cần biết chính xác để gửi Zalo kèm đủ chi
// tiết tiền phòng/điện/nước/nợ cũ giống hệt phiếu in, xem MinihouseZaloService::buildTemplateData().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('contract_id')
                ->constrained('minihouse_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
