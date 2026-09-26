<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhóm vật tư — DÙNG CHUNG cho mọi Toà nhà (không có building_id), mirror đúng lý do bên Home
// (Modules\Minihouse\App\Models\WarehouseCategory): MiniHouse không có khái niệm "đối tác" như Home
// nên danh mục này chỉ còn 1 tầng DUY NHẤT — dùng chung toàn hệ thống thay vì tách theo đối tác.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_warehouse_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_warehouse_categories');
    }
};
