<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lịch sử xác thực CCCD của khách (lần 1, 2, 3...). customers.cccd_data luôn là CCCD mới nhất
    // (dùng khi đặt phòng); bảng này giữ lại MỌI lần xác thực để admin xem khách đã đổi CCCD thế
    // nào ở trang quản lý thành viên — vd xác thực bằng CCCD của mình rồi đổi sang CCCD người khác.
    public function up(): void
    {
        Schema::create('customer_cccd_verifications', function (Blueprint $table) {
            $table->id();
            $table->char('customer_id', 36);
            $table->unsignedInteger('attempt');
            $table->string('cccd_qr_image')->nullable();
            $table->json('cccd_data')->nullable();
            // true = cùng SỐ CCCD với lần xác thực đầu tiên; false = khách đã đổi sang CCCD khác.
            $table->boolean('same_as_first');
            // Nơi xác thực: web_account (trang cá nhân), legacy (CCCD có sẵn trong hồ sơ trước khi
            // có bảng này — ghi làm lần 1)...
            $table->string('source', 30);
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->unique(['customer_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_cccd_verifications');
    }
};
