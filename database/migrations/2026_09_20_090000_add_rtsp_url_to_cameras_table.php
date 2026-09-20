<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            // Địa chỉ RTSP + tài khoản/mật khẩu camera thật — trước đây cố tình KHÔNG lưu ở web,
            // bắt phải tự khai báo tay trên server go2rtc (YAML). Theo yêu cầu "cấu hình trực tiếp
            // tại website luôn": lưu ở đây (mã hoá bằng APP_KEY qua cast 'encrypted' — xem
            // App\Models\Camera), rồi tự đẩy sang go2rtc qua HTTP API mỗi khi lưu (xem
            // App\Services\Go2RtcClient), không cần SSH vào server go2rtc để sửa file cấu hình nữa.
            $table->text('rtsp_url')->nullable()->after('stream_key');
        });
    }

    public function down(): void
    {
        Schema::table('cameras', function (Blueprint $table) {
            $table->dropColumn('rtsp_url');
        });
    }
};
