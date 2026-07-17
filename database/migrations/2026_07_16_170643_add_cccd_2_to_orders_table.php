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
        Schema::table('orders', function (Blueprint $table) {
            // CCCD của người đi cùng — chỉ bắt buộc khi đơn có khung giờ qua đêm
            // (room_time_slots.over_night), xem ProductDetail::hasOvernightSlotSelected().
            $table->text('cccd_front_2')->nullable()->after('cccd_back');
            $table->text('cccd_back_2')->nullable()->after('cccd_front_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cccd_front_2', 'cccd_back_2']);
        });
    }
};
