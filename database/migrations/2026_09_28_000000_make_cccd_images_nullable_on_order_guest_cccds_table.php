<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Luồng khách chuyển sang 1 ảnh CCCD mặt có mã QR (cột cccd_qr_image) — người đi cùng không
        // còn ảnh mặt trước/sau riêng; admin vẫn có thể bổ sung sau nên 2 cột này thành tuỳ chọn.
        Schema::table('order_guest_cccds', function (Blueprint $table) {
            $table->string('cccd_front')->nullable()->change();
            $table->string('cccd_back')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_guest_cccds', function (Blueprint $table) {
            $table->string('cccd_front')->nullable(false)->change();
            $table->string('cccd_back')->nullable(false)->change();
        });
    }
};
