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
        Schema::table('order_items', function (Blueprint $table) {
            // Cờ khung giờ qua đêm tại thời điểm đặt (sao chép từ room_time_slots.over_night) —
            // lưu trực tiếp trên order_item để tra cứu đơn có qua đêm hay không mà không phải suy
            // luận qua slot_label (chưa từng được set khi tạo item) hay so khớp giờ với time_slots.
            // Dùng để quyết định hiển thị CCCD người đi cùng (cccd_front_2/back_2) ở form admin.
            $table->boolean('over_night')->default(false)->after('slot_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('over_night');
        });
    }
};
