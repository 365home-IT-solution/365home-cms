<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép gửi phản hồi NGAY TRONG Portal (đã đăng nhập) thay vì chỉ qua link công khai ẩn danh như
// trước — nullable vì phản hồi công khai cũ (TenantFeedbackController) vẫn không gắn tenant nào.
// Có tenant_id thì khi chủ nhà xử lý xong (is_reviewed=true) sẽ báo lại được ĐÚNG khách trong Portal
// (xem TenantFeedbackObserver).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenant_feedbacks', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('room_id')->constrained('minihouse_tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenant_feedbacks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
