<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Log chỉ số điện/nước theo TỪNG PHÒNG, TỪNG THÁNG — độc lập với hoá đơn (minihouse_invoices), để
// nhân viên ghi số điện/nước hàng tháng riêng, hoá đơn sau đó tự lấy số liệu từ đây thay vì nhập tay
// trực tiếp trên form hoá đơn. Đơn giá điện/nước KHÔNG nằm ở đây — vẫn giữ nguyên ở
// minihouse_buildings/minihouse_contracts như hiện tại (module này chỉ quản lý CHỈ SỐ, không quản lý
// GIÁ).
//
// room_id trỏ thẳng minihouse_rooms (không cascade) — chỉ số gắn với PHÒNG (đồng hồ điện/nước vật lý
// gắn ở phòng), không gắn với hợp đồng/khách thuê, để sống sót qua chuyển phòng/đổi khách thuê, đúng
// nguyên tắc chuỗi tra electric_start hiện có trong InvoiceGenerationService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metering_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('minihouse_rooms')->cascadeOnDelete();
            // Luôn lưu ngày 1 đầu tháng — đại diện cho "tháng ghi số", không phải ngày ghi thực tế.
            $table->date('month');
            $table->decimal('electric_start', 12, 2)->nullable();
            $table->decimal('electric_end', 12, 2)->nullable();
            $table->decimal('water_start', 12, 2)->nullable();
            $table->decimal('water_end', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metering_readings');
    }
};
