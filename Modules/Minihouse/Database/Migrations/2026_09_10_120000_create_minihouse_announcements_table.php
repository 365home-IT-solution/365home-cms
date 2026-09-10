<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thông báo chung chủ nhà tự đăng cho khách thuê (VD cắt nước, bảo trì thang máy) — building_id NULL
// nghĩa là gửi cho TẤT CẢ khách thuê ở mọi toà, có building_id thì chỉ khách đang thuê đúng toà đó
// nhận được. Tạo xong tự fan-out ra minihouse_portal_notifications (xem AnnouncementObserver).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->nullable()->constrained('minihouse_buildings')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_announcements');
    }
};
