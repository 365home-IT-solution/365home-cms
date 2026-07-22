<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // room_amenities/additional_services vừa thêm cột partner_id sau migration backfill chính
    // (2026_07_10_100011) nên chưa được gán — gán nốt về đối tác mặc định (dữ liệu cũ) để không
    // "biến mất" khỏi tầm nhìn của tài khoản đối tác sau khi bật lọc theo partner_id.
    public function up(): void
    {
        $hasUnassigned = DB::table('room_amenities')->whereNull('partner_id')->exists()
            || DB::table('additional_services')->whereNull('partner_id')->exists();

        if (! $hasUnassigned) {
            return;
        }

        // Tra theo tên "365Home" — migration này chạy TRƯỚC cả lúc cột is_platform_partner được
        // tạo ra (2026_07_13_120001), nên chưa dùng cột đó để tra được ở đây (đã kiểm chứng thực
        // tế: "Column not found" khi thử). 'name' đã tồn tại ngay từ migration backfill chính
        // (2026_07_10_100011) nên tra được bằng tên tại đúng thời điểm này.
        $partnerId = DB::table('partners')
            ->where('name', '365Home')
            ->value('id');

        if (! $partnerId) {
            return;
        }

        DB::table('room_amenities')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
        DB::table('additional_services')->whereNull('partner_id')->update(['partner_id' => $partnerId]);
    }

    public function down(): void
    {
        // Không hoàn tác — xem ghi chú ở migration backfill chính (2026_07_10_100011).
    }
};
