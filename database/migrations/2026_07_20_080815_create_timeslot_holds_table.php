<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Giữ chỗ tạm real-time cho lưới NGÀY × KHUNG GIỜ ở admin — 1 dòng = 1 khung giờ (room_time_
        // slot_id + date) đang được 1 admin thao tác chọn, hết hạn sau vài phút nếu không hoàn tất
        // đặt phòng (dọn bằng expires_at, không cần job riêng — xem TimeslotHoldService).
        Schema::create('timeslot_holds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_time_slot_id');
            $table->date('date');
            $table->uuid('user_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('room_time_slot_id')->references('id')->on('room_time_slots')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['room_time_slot_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timeslot_holds');
    }
};
