<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vị trí (hàng/cột) của phòng trên MẶT BẰNG tầng — để RoomOccupancyMapWidget (Dashboard) vẽ đúng sơ
// đồ theo bố trí THẬT của nhà (dãy phòng, hành lang...) thay vì xếp theo thứ tự mã phòng chung chung.
// Nullable — phòng chưa nhập vị trí vẫn quản lý bình thường, chỉ không lên được đúng ô trên sơ đồ
// (hiện ở khu "Chưa gán vị trí" riêng, xem widget).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('position_row')->nullable()->after('floor');
            $table->unsignedSmallInteger('position_col')->nullable()->after('position_row');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->dropColumn(['position_row', 'position_col']);
        });
    }
};
