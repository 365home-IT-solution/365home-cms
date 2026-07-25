<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mô tả ngắn hiển thị ở danh sách đơn/thông báo (khác 'description' — mô tả đầy đủ hiện có,
    // thường tự sinh dạng "Đặt phòng - {tên phòng}") — cho admin tự nhập khi tạo/sửa đơn qua API.
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('short_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('short_description');
        });
    }
};
