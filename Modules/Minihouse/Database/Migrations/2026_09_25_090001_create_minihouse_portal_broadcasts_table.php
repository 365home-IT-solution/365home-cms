<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhân viên soạn + gửi 1 thông báo cho TẤT CẢ/1 khách/nhóm khách thuê — mirror App\Models\
// NotificationFcm (Home) nhưng KHÔNG dựng lại kiểu "1 bản ghi dùng chung + bảng recipient riêng
// theo dõi từng người" — mỗi khách nhận vẫn là 1 dòng minihouse_portal_notifications bình thường
// (qua PortalNotificationService::notifyMany(), xem PushNotificationController), bảng này CHỈ lưu
// lại chính LẦN GỬI đó (tiêu đề/nội dung/tiêu chí người nhận/lịch gửi) để nhân viên xem lại lịch sử,
// sửa trước khi gửi, và gửi lại — kết quả người dùng cuối THẤY GIỐNG HỆT Home (lưu lại, đếm số nhận
// được/lỗi, sửa được khi chưa gửi, gửi lại được), chỉ khác ở tầng lưu trữ bên dưới.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_portal_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('link', 500)->nullable();
            // 'all' = mọi khách thuê đang có ít nhất 1 thiết bị đăng ký nhận push; 'tenants' = đúng
            // danh sách tenant_ids bên dưới — mirror enum sent_for('all','users') của Home, đổi tên
            // 'users' thành 'tenants' cho đúng thuật ngữ MiniHouse.
            $table->enum('sent_for', ['all', 'tenants'])->default('tenants');
            // Snapshot danh sách khách CHỈ lưu khi CÓ lên lịch tương lai (giống recipient_ids của
            // Home) — gửi ngay thì suy ngược lại từ chính các dòng PortalNotification đã tạo, không
            // cần lưu trùng ở đây.
            $table->json('tenant_ids')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('recipient_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_portal_broadcasts');
    }
};
