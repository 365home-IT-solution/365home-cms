<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Toà nhà 1 tài khoản (App\Models\User, bảng users dùng chung với Home — users.id là UUID) được
// CHỦ ĐỘNG gán quyền quản lý — xem User::minihouseBuildings()/rootBuildingIds(). Chưa có dòng nào
// cho 1 user = mặc định KHÔNG giới hạn (xem rootBuildingIds()), không phải "không thấy gì".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_user_buildings', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->foreignId('building_id')->constrained('minihouse_buildings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->primary(['user_id', 'building_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_user_buildings');
    }
};
