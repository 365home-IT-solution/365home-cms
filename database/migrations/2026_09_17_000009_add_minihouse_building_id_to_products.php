<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Home nối Product<->Category qua bảng trung gian nhiều-nhiều `categorizables` (không có cột
// building_id trực tiếp trên products) — nhưng TOÀN BỘ hệ phân quyền theo toà nhà của MiniHouse (4
// trait ScopedToActiveBuilding*, xem ActiveBuildingScope) lọc SQL trực tiếp theo CỘT building_id,
// không thể lọc qua bảng trung gian mà không viết lại các trait đó (rủi ro cao, đụng nhiều nơi hơn).
// Thêm cột building_id nullable CHỈ để MiniHouse dùng — Home không đọc/ghi cột này ở đâu cả, không
// ảnh hưởng gì tới Product của Home. Giá trị luôn đồng bộ với đúng 1 category đã gắn qua
// categorizables (xem Room::syncCategoryFromBuildingId()).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'building_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('building_id')->nullable()->after('room_type_id')
                    ->constrained('categories')->nullOnDelete();
            });
        }

        // Backfill cho 32 phòng đã chuyển ở Giai đoạn 2 — lấy đúng category đã gắn qua
        // categorizables (mỗi phòng MiniHouse chỉ gắn đúng 1 chi nhánh).
        $prefix = DB::getTablePrefix();
        DB::statement("
            UPDATE `{$prefix}products` p
            INNER JOIN `{$prefix}categorizables` cz
                ON cz.categorizable_id = p.id AND cz.categorizable_type = 'Modules\\\\Product\\\\App\\\\Models\\\\Product'
            SET p.building_id = cz.category_id
            WHERE p.legacy_minihouse_room_id IS NOT NULL AND p.building_id IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'building_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['building_id']);
                $table->dropColumn('building_id');
            });
        }
    }
};
