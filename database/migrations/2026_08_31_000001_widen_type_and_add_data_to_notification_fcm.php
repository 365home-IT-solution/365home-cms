<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Trước đây `type` là ENUM cứng, đã phải sửa bằng migration 3 lần mỗi khi thêm loại mới (booking,
// checkin_reminder, checkout_warning, membership_auto_coupon, checkin_streak_reminder) — nay khách
// hàng cũng cần type chi tiết như admin (order_pending, booking_confirmation, payment, order_update,
// message) nên đổi hẳn sang chuỗi tự do để không phải lặp lại việc này.
// `data` (JSON) lưu ngữ cảnh đi kèm (order_code, conversation_id...) — trước đây các field này CHỈ
// tới được payload push FCM lúc gửi (ephemeral), không có chỗ lưu lại nên GET /api/notifications
// không trả về được, xem App\Services\NotificationFcmService.
return new class extends Migration
{
    public function up(): void
    {
        $table = DB::getTablePrefix().'notification_fcm';

        DB::statement("ALTER TABLE `{$table}` MODIFY `type` VARCHAR(50) NOT NULL DEFAULT 'manual'");

        if (! Schema::hasColumn('notification_fcm', 'data')) {
            Schema::table('notification_fcm', function (Blueprint $table) {
                $table->json('data')->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->dropColumn('data');
        });

        $table = DB::getTablePrefix().'notification_fcm';
        DB::statement("ALTER TABLE `{$table}` MODIFY `type` ENUM('manual', 'booking', 'checkin_reminder', 'checkout_warning', 'membership_auto_coupon', 'checkin_streak_reminder') NOT NULL DEFAULT 'manual'");
    }
};
