<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lịch sử gửi SMS cho khách thuê MiniHouse — cùng mẫu minihouse_zalo_notifications, nullOnDelete
// cho reminder_id (xoá nhắc việc không mất lịch sử đã gửi, chỉ mất liên kết).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_sms_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->nullable()->constrained('minihouse_reminders')->nullOnDelete();
            $table->string('phone_number', 20);
            $table->string('recipient_name')->nullable();
            $table->text('content');
            $table->enum('status', ['sent', 'failed'])->default('failed');
            $table->string('sms_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('reminder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_sms_notifications');
    }
};
