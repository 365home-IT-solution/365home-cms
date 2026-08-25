<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Luật Cư trú yêu cầu khai báo ĐỦ TỪNG NGƯỜI lưu trú qua đêm — 1 đơn qua đêm có 2 khách
        // cần 2 bản khai báo riêng (trước đây bảng này chỉ cho phép ĐÚNG 1 dòng/đơn qua ràng buộc
        // order_id unique). 'guest_index': 1 = khách chính (người đặt phòng), 2 = khách thứ 2.
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->unsignedTinyInteger('guest_index')->default(1)->after('order_id');
        });

        // Thêm index thường TRƯỚC khi bỏ unique — khoá ngoại order_id vẫn cần 1 index còn lại để
        // hợp lệ, nếu bỏ unique trước khi có index khác thay thế sẽ lỗi ở một số driver DB.
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->index('order_id');
        });

        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->unique(['order_id', 'guest_index']);
        });
    }

    public function down(): void
    {
        Schema::table('cccd_declarations', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'guest_index']);
            $table->dropIndex(['order_id']);
            $table->unique('order_id');
            $table->dropColumn('guest_index');
        });
    }
};
