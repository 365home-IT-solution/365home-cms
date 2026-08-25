<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khách thứ 2 trở đi khi đặt qua đêm (guest_index = 2, 3, 4...) — khách 1 (người đặt
        // chính) vẫn dùng orders.cccd_front/back/data như cũ. orders.cccd_front_2/back_2/data_2
        // GIỮ NGUYÊN chỉ để đọc dữ liệu đơn cũ (trước migration này) — đơn mới không ghi vào đó
        // nữa, tất cả khách 2+ đi qua bảng này để guest_count có thể > 2 khi đặt qua đêm thay vì
        // bị chặn cứng ở 2 như trước.
        Schema::create('order_guest_cccds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedTinyInteger('guest_index')->comment('Thứ tự khách: 2, 3, 4...');
            $table->string('cccd_front');
            $table->string('cccd_back');
            $table->json('cccd_data')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->unique(['order_id', 'guest_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_guest_cccds');
    }
};
