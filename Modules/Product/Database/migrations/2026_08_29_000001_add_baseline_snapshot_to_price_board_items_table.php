<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Chụp "giá gốc" của phòng NGAY LÚC TẠO 1 bảng giá đặt tên — đóng băng vĩnh viễn, không đổi theo
    // dù sau đó admin có sửa gì thêm ở trang "Hệ thống giá" — dùng làm giá khôi phục khi CHÍNH bảng
    // này hết hạn, thay vì luôn đọc "Bảng giá mặc định" (vốn liên tục cập nhật theo lần lưu mới
    // nhất, không phải giá tại đúng thời điểm tạo bảng khuyến mãi).
    public function up(): void
    {
        Schema::table('price_board_items', function (Blueprint $table) {
            if (! Schema::hasColumn('price_board_items', 'baseline_fields')) {
                $table->longText('baseline_fields')->nullable()->after('default_checkout');
            }

            if (! Schema::hasColumn('price_board_items', 'baseline_time_slots')) {
                $table->longText('baseline_time_slots')->nullable()->after('baseline_fields');
            }
        });
    }

    public function down(): void
    {
        Schema::table('price_board_items', function (Blueprint $table) {
            $table->dropColumn(['baseline_fields', 'baseline_time_slots']);
        });
    }
};
