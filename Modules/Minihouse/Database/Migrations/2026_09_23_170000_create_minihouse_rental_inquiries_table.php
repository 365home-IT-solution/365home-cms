<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Yêu cầu liên hệ thuê phòng" — gửi từ trang công khai (chưa đăng nhập, chưa là khách thuê) khi
// người xem quan tâm 1 phòng/toà nhà cụ thể đang còn trống. KHÔNG phải Hợp đồng/Tenant — chỉ là 1
// "lead" để nhân viên gọi lại tư vấn, tự tạo Hợp đồng/Tenant thật (nếu chốt) qua panel như bình
// thường. Tách bảng riêng (không tái dùng Tenant) vì người gửi CHƯA CHẮC sẽ thuê, và chưa có đủ
// thông tin (CCCD, ngày vào ở...) để tạo 1 Tenant thật.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_rental_inquiries', function (Blueprint $table) {
            $table->id();
            // Cả 2 đều nullable — người xem có thể quan tâm 1 PHÒNG cụ thể (room_id) hoặc chỉ 1 TOÀ
            // NHÀ nói chung (building_id, room_id để trống) khi mới xem danh sách toà nhà.
            $table->uuid('room_id')->nullable()->comment('Phòng quan tâm (products.id)');
            $table->unsignedBigInteger('building_id')->nullable()->comment('Toà nhà quan tâm (categories.id)');
            $table->string('full_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->date('preferred_move_in_date')->nullable();
            // new = chưa liên hệ, contacted = đã gọi tư vấn, closed = xong việc (chốt thuê hoặc khách
            // không còn nhu cầu) — nhân viên tự cập nhật thủ công trên panel, không có luồng tự động.
            $table->string('status')->default('new');
            $table->text('staff_note')->nullable()->comment('Ghi chú nội bộ của nhân viên khi xử lý');
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('building_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_rental_inquiries');
    }
};
