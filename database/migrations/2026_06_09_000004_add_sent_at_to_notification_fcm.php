<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            // Thời điểm thực sự gửi (null = chưa gửi / đang chờ lịch)
            $table->timestamp('sent_at')->nullable()->after('scheduled_at');
            // Danh sách customer_ids lưu khi lên lịch để command biết gửi cho ai
            $table->json('recipient_ids')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'recipient_ids']);
        });
    }
};
