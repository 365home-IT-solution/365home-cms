<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lịch sử gửi ZNS cho khách thuê MiniHouse — nullOnDelete cho reminder_id (xoá nhắc việc không mất
// lịch sử đã gửi, chỉ mất liên kết). Tách bảng riêng khỏi zns_notifications của Home (khác tài
// khoản Zalo, khác vòng đời dữ liệu — Home gắn theo Order, ở đây gắn theo Reminder).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_zalo_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->nullable()->constrained('minihouse_reminders')->nullOnDelete();
            $table->string('phone_number', 20);
            $table->string('recipient_name')->nullable();
            $table->string('template_id', 50);
            $table->json('template_data')->nullable();
            $table->enum('status', ['sent', 'failed'])->default('failed');
            $table->string('zalo_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('reminder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_zalo_notifications');
    }
};
