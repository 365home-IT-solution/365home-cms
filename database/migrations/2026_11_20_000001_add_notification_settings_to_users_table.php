<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cài đặt nhận thông báo của từng tài khoản admin: { enabled, types: { order, payment, ... } }.
        // NULL = chưa chỉnh gì → mặc định bật hết (xem App\Support\AdminNotificationCategory::normalizeSettings()).
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};
