<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirror App\Models\NotificationFcm.data (Home) — lưu lại context (conversation_id, contract_id,
// room_code, invoice_id...) CÙNG với thông báo, không chỉ đúc vào gói push lúc gửi như trước (mất
// hẳn nếu khách mở lại danh sách "Thông báo" sau này thay vì bấm thẳng từ push). Không dùng lại tên
// cột "data" trùng convention Laravel Notification mặc định — ở đây là bảng riêng
// (minihouse_portal_notifications), không xung đột.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_portal_notifications', function (Blueprint $table) {
            $table->json('data')->nullable()->after('link');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_portal_notifications', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
