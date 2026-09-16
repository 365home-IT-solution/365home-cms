<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Support\HomestayBridge;

// Giai đoạn 1 của việc gộp Phòng/Toà nhà MiniHouse vào products/categories: tạo sẵn 1 đối tác nội
// bộ "MiniHouse" (partners.id CỐ ĐỊNH, xem HomestayBridge::PARTNER_ID) và 1 loại phòng room_types
// "minihouse" (slug CỐ ĐỊNH) — chưa đụng gì tới dữ liệu MiniHouse hiện có, chỉ chuẩn bị 2 giá trị
// tham chiếu để Giai đoạn 2 (data migration) và các bước sau dùng lại.
return new class extends Migration
{
    public function up(): void
    {
        $partnerExists = DB::table('partners')->where('id', HomestayBridge::PARTNER_ID)->exists();

        if (! $partnerExists) {
            DB::table('partners')->insert([
                'id'         => HomestayBridge::PARTNER_ID,
                'name'       => 'MiniHouse (nội bộ)',
                'status'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roomTypeExists = DB::table('room_types')->where('slug', HomestayBridge::ROOM_TYPE_SLUG)->exists();

        if (! $roomTypeExists) {
            DB::table('room_types')->insert([
                'slug'       => HomestayBridge::ROOM_TYPE_SLUG,
                'name'       => 'Cho thuê dài hạn (MiniHouse)',
                // is_active=false: KHÔNG cho khách chọn lọc theo loại phòng này ở trang tìm phòng
                // công khai của Home — phòng MiniHouse chỉ quản lý qua panel /minihouse/admin.
                'is_active'  => false,
                'sort_order' => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // KHÔNG xoá lại đối tác/loại phòng ở đây — tới lúc down() chạy có thể đã có
        // products/categories thật tham chiếu tới, xoá sẽ vi phạm khoá ngoại. Dọn dẹp thủ công nếu
        // thật sự cần rollback toàn bộ (xem Giai đoạn 5 của kế hoạch).
    }
};
