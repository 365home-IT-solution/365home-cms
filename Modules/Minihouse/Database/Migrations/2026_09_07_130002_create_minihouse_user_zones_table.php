<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gán 1 tài khoản quản lý CẢ 1 Khu vực (mọi toà nhà thuộc khu, kể cả toà thêm sau này) — dùng CHUNG
// với minihouse_user_buildings (gán riêng lẻ từng toà) để tính User::rootBuildingIds(): hợp (UNION)
// toà nhà được gán trực tiếp VÀ toà nhà thuộc khu vực được gán.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_user_zones', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->foreignId('zone_id')->constrained('minihouse_zones')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->primary(['user_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_user_zones');
    }
};
