<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bảng tham chiếu RIÊNG cho tính năng "Khai báo lưu trú" — trích nguyên văn sheet
        // TINH_THANH của mẫu chính thức tblt_vn_import.xlsx (mã MATT + tên riêng của Bộ Công an,
        // KHÁC hoàn toàn với mã trong bảng `provinces` hiện có (đang dùng mã provinces.open-api.vn
        // cho địa chỉ giao hàng/chi nhánh) — tách bảng riêng để KHÔNG đụng chạm/phá vỡ dữ liệu
        // tỉnh/thành cũ đang được dùng ở nơi khác.
        Schema::create('tblt_provinces', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('display');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblt_provinces');
    }
};
