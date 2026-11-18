<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cờ chống gửi trùng thông báo nhắc nhận/trả xe và quá hạn trả xe (lệnh vehicle-rental:send-reminders chạy mỗi 5 phút).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dateTime('pickup_reminded_at')->nullable()->after('settled_at');
            $table->dateTime('return_reminded_at')->nullable()->after('pickup_reminded_at');
            $table->dateTime('overdue_notified_at')->nullable()->after('return_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicle_bookings', function (Blueprint $table) {
            $table->dropColumn(['pickup_reminded_at', 'return_reminded_at', 'overdue_notified_at']);
        });
    }
};
