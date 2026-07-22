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
        // Sheet PHUONG_XA của mẫu — 3323 phường/xã/đặc khu theo cấu trúc 2 cấp mới (2025), mỗi
        // dòng gắn với đúng 1 mã tỉnh (province_code) của bảng tblt_provinces ở trên.
        Schema::create('tblt_wards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 15)->unique();
            $table->string('name');
            $table->string('display');
            $table->string('province_code', 10);
            $table->timestamps();

            $table->index('province_code');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblt_wards');
    }
};
