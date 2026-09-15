<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Sơ đồ 360°" — mỗi dòng là 1 ảnh toàn cảnh (equirectangular) đại diện 1 điểm đứng thật (sảnh, hành
// lang, hoặc 1 phòng cụ thể), khách xem xoay 360° tại chỗ rồi bấm điểm nóng (hotspot, xem
// minihouse_panorama_hotspots) để "đi" sang điểm khác — mô phỏng tham quan thực tế mà KHÔNG cần quét
// 3D/LiDAR (rất nặng, tốn thiết bị) — chỉ cần 1 ảnh 360° chụp bằng điện thoại cho mỗi điểm.
// room_id ĐỂ TRỐNG = điểm đứng chung (sảnh/hành lang/cầu thang), có giá trị = ảnh chụp bên TRONG
// đúng phòng đó.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_panorama_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('minihouse_buildings')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('minihouse_rooms')->nullOnDelete();
            $table->string('title');
            // Tầng — CHỈ để nhóm/sắp xếp danh sách trong trang quản trị (giống Room.floor), không ảnh
            // hưởng cách khách xem/di chuyển giữa các điểm (việc đó do hotspot quyết định).
            $table->unsignedInteger('floor')->nullable();
            $table->string('image_path');
            $table->string('thumbnail_path')->nullable();
            // Góc nhìn ban đầu khi mới vào scene này — mặc định nhìn thẳng ra cửa/hướng chính, tránh
            // khách vừa vào đã thấy ngay 1 góc tường trống.
            $table->float('initial_yaw')->default(0);
            $table->float('initial_pitch')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            // Cho phép admin tải ảnh lên TRƯỚC rồi mới công khai sau (VD đang chụp dở cả toà, chưa
            // muốn khách thấy tour thiếu nửa chừng) — mặc định TRUE để không đổi hành vi khi tạo mới.
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_panorama_scenes');
    }
};
