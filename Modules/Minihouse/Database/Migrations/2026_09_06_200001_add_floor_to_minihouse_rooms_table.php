<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thêm số tầng — trước đây Room chỉ có 'code' tự do, không group được theo tầng để vẽ sơ đồ phòng ở
// Dashboard (RoomOccupancyMapWidget). Nullable — phòng cũ chưa nhập tầng vẫn hiển thị được, gộp vào
// nhóm "Khác".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('floor')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->dropColumn('floor');
        });
    }
};
