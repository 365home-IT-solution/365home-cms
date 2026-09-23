<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "camera_name" Frigate dùng cho API lịch sử ghi hình/sự kiện (/api/{camera_name}/recordings,
// /api/events/{camera_name}/{label}/create) KHÔNG LUÔN TRÙNG với "stream_key" (tên nguồn go2rtc
// dùng để xem trực tiếp) — đã xác nhận thực tế ở phần xem camera trực tiếp: Frigate hiển thị tên có
// hoa/thường/dấu gạch khác với tên go2rtc thật sự đăng ký (VD "254-Lau-1" ở Frigate UI vs
// "254-lau-1" ở go2rtc). Thêm cột RIÊNG, để trống thì các API lịch sử/ghi hình tự dùng lại
// stream_key (đúng cho đa số camera cùng tên), chỉ cần điền khi camera đó thực sự lệch tên.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->string('frigate_camera_name')->nullable()->after('stream_key');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->dropColumn('frigate_camera_name');
        });
    }
};
