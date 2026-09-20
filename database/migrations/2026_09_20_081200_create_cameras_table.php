<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->string('partner_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('name');
            // Tên "src" khai báo cho go2rtc (Frigate hoặc go2rtc/MediaMTX độc lập) — go2rtc dùng
            // để build URL phát HLS/MSE: /api/stream.m3u8?src=<stream_key>. KHÔNG lưu URL RTSP thật
            // của camera ở đây (thông tin nhạy cảm nằm trong cấu hình go2rtc trên server chuyển đổi,
            // không phải trong CSDL web) — bảng này chỉ giữ tên tham chiếu + ghi chú vận hành.
            $table->string('stream_key');
            $table->text('note')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cameras');
    }
};
