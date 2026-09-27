<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cấu hình server go2rtc/Frigate THEO TỪNG TOÀ NHÀ MiniHouse (khoá chính = building_id, tức
// categories.id) — khác với App\Models\CameraSetting của Home (khoá chính = partner_id, DÙNG CHUNG
// cho mọi chi nhánh của 1 đối tác). MiniHouse chỉ có ĐÚNG 1 đối tác nội bộ cố định
// (HomestayBridge::PARTNER_ID) nên không lọc được theo đối tác như Home — yêu cầu thực tế: mỗi Toà
// nhà MiniHouse là 1 địa điểm vật lý riêng, có thể có server Frigate/go2rtc RIÊNG, nên đổi sang lọc
// theo building_id. Xem Modules\Minihouse\App\Models\CameraSetting (kế thừa App\Models\CameraSetting,
// chỉ đổi bảng/khoá chính).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_camera_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('building_id')->primary();
            $table->foreign('building_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_camera_settings');
    }
};
