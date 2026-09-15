<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Điểm nóng" (hotspot) trên 1 ảnh 360° (minihouse_panorama_scenes) — khách bấm vào điểm này để mượt
// mà "đi" sang scene khác (đúng cơ chế cốt lõi của mọi tour ảo 360° — Matterport/Google Street View
// đều dùng chung nguyên tắc "đồ thị các điểm nối nhau bằng điểm nóng" này). target_scene_id
// nullOnDelete() — xoá 1 scene không kéo theo xoá luôn các hotspot TRỎ ĐẾN nó ở scene khác, chỉ làm
// hotspot đó tự vô hiệu (ẩn ở client, xem PanoramaTourController), tránh vỡ toàn bộ tour chỉ vì xoá
// nhầm 1 điểm.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_panorama_hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained('minihouse_panorama_scenes')->cascadeOnDelete();
            $table->foreignId('target_scene_id')->nullable()->constrained('minihouse_panorama_scenes')->nullOnDelete();
            // Toạ độ góc (độ) trên ảnh 360° — yaw: trái/phải (-180..180), pitch: trên/dưới (-90..90).
            $table->float('yaw');
            $table->float('pitch');
            $table->string('label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_panorama_hotspots');
    }
};
