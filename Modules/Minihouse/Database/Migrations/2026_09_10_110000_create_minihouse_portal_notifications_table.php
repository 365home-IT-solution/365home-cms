<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nguồn thông báo TRONG Portal khách thuê — 1 dòng = 1 thông báo cho ĐÚNG 1 khách thuê (không phải
// cho cả SĐT dùng chung, vì mỗi hồ sơ Tenant tự có phiên đăng nhập riêng). 4 nguồn tạo ra dòng ở đây:
// hoá đơn mới (InvoiceObserver), nhắc việc đến hạn gửi khách (ReminderNotificationService), thông
// báo chung do chủ nhà đăng (Announcement, tự fan-out), và phản hồi được xử lý (TenantFeedbackObserver).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('minihouse_tenants')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            // Đường dẫn TƯƠNG ĐỐI trong chính Portal (VD "/minihouse/portal/invoices/12") — không lưu
            // route name vì có thể đổi tên sau này, lưu path đã build sẵn cho đơn giản.
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_portal_notifications');
    }
};
