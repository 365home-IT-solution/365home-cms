<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Đánh dấu đối tác ĐẶC BIỆT do chính nền tảng vận hành (vd "365home" — nơi gộp dữ liệu cũ trước
// khi có multi-tenant). Nhân viên của đối tác này được phép TẠO ĐƠN ĐẶT PHÒNG cho phòng/chi
// nhánh của BẤT KỲ đối tác nào khác (đóng vai trò như tổng đài đặt phòng hộ) — các dữ liệu khác
// (doanh thu, lương, hồ sơ đối tác...) vẫn tách biệt bình thường theo BelongsToPartner, KHÔNG cấp
// quyền toàn cục kiểu super_admin.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_platform_partner')->default(false)->after('verification_status');
        });

        // Đối tác "365Home" (tạo ở migration backfill 2026_07_10_100011, TRƯỚC khi cột
        // verification_status/is_platform_partner tồn tại nên không gán được lúc đó) chính là nền
        // tảng — đánh dấu is_platform_partner=true + verification_status='approved' ngay khi 2 cột
        // này đã chắc chắn tồn tại. Không phải đối tác thật cần chờ duyệt — để mặc định 'pending'
        // sẽ khiến dropdown "Đối tác" ở form đặt phòng ẩn luôn đối tác này, chặn đặt phòng.
        DB::table('partners')
            ->where('name', '365Home')
            ->update([
                'verification_status' => 'approved',
                'is_platform_partner'  => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('is_platform_partner');
        });
    }
};
