<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bổ sung sau khi rà lại Room::$fillable — minihouse_rooms.photos (json, ảnh chụp phòng) chưa có
// chỗ chứa tương đương ở Product (Product dùng Spatie MediaLibrary cho ảnh, không phải cột json;
// tích hợp MediaLibrary cho ảnh cũ nằm ngoài phạm vi giai đoạn gộp bảng này) — giữ tạm dạng json ở
// đây để KHÔNG mất dữ liệu ảnh đã chụp, có thể chuyển sang MediaLibrary sau như 1 việc riêng.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('minihouse_room_details', 'photos')) {
            Schema::table('minihouse_room_details', function (Blueprint $table) {
                $table->json('photos')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('minihouse_room_details', 'photos')) {
            Schema::table('minihouse_room_details', function (Blueprint $table) {
                $table->dropColumn('photos');
            });
        }
    }
};
