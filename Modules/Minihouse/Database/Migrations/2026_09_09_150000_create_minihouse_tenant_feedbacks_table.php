<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kênh phản hồi/đánh giá của khách thuê — KHÔNG cần đăng nhập portal riêng (chưa xây, xem "Giai
// đoạn sau" trong báo cáo tổng hợp): khách quét QR/mở link công khai gắn với 1 phòng (xem
// TenantFeedbackController), chấm sao + góp ý, chủ nhà xem/xử lý trong panel MiniHouse. room_id
// nullable — vẫn nhận được phản hồi CHUNG không gắn phòng cụ thể nếu link không kèm room.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_tenant_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->nullable()->constrained('minihouse_rooms')->nullOnDelete();
            $table->string('tenant_name')->nullable();
            $table->string('tenant_phone')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('content')->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->text('staff_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_tenant_feedbacks');
    }
};
