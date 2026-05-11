<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zns_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('access_code_id')->nullable();
            $table->string('phone_number', 20);
            $table->string('recipient_name')->nullable();
            $table->string('template_id', 50);
            $table->string('template_name')->nullable();
            $table->longText('template_data');
            $table->enum('status', ['pending', 'sent', 'failed', 'delivered', 'read'])->default('pending')
                ->comment('Trạng thái: pending=chờ gửi, sent=đã gửi, failed=thất bại, delivered=đã nhận, read=đã đọc');
            $table->string('zns_message_id')->nullable();
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('response_data')->nullable()->comment('Full response từ Zalo API');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable()->comment('Thời điểm khách nhận được');
            $table->timestamp('read_at')->nullable()->comment('Thời điểm khách đọc');
            $table->timestamp('failed_at')->nullable()->comment('Thời điểm thất bại');
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('notification_type')->default('booking_success')
                ->comment('Loại thông báo: booking_success, booking_reminder, booking_cancelled, etc.');
            $table->longText('metadata')->nullable()->comment('Dữ liệu bổ sung');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zns_notifications');
    }
};
