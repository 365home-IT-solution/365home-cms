<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Giai đoạn 1 của việc gộp Phòng MiniHouse vào products: bảng phụ 1-1 giữ các cột riêng của việc
// cho thuê dài hạn mà Product (dùng cho đặt phòng ngắn hạn) không có — vị trí ô lưới sơ đồ tầng,
// trạng thái thuê (trống/đã cọc/đang thuê/bảo trì). Khoá chính là product_id (char(36), ULID —
// Product dùng HasUlids, KHÔNG PHẢI UUID chuẩn 36 ký tự có dấu gạch ngang, nhưng vẫn vừa cột
// char(36)) — KHÁC HẲN kiểu minihouse_rooms.id cũ (bigint auto-increment).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('minihouse_room_details')) {
            return;
        }

        Schema::create('minihouse_room_details', function (Blueprint $table) {
            $table->char('product_id', 36)->primary();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->unsignedSmallInteger('floor')->nullable();
            $table->unsignedSmallInteger('position_row')->nullable();
            $table->unsignedSmallInteger('position_col')->nullable();
            $table->string('status')->default('trong'); // trong|dat_coc|dang_thue|bao_tri

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_room_details');
    }
};
