<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đánh dấu đã gửi thông báo cho reminder này chưa — tránh lệnh chạy hàng ngày gửi lặp lại thông báo
// cũ mỗi lần chạy (xem app/Console/Commands/Minihouse/SendReminderNotificationsCommand.php).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('is_done');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
