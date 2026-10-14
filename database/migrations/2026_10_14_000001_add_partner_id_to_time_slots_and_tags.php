<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Khung giờ (time_slots) và tiện ích phòng (tags) trước nay dùng CHUNG toàn hệ thống — đối tác
    // A sửa 1 khung giờ là đổi luôn phòng của mọi đối tác khác. Thêm partner_id: dòng partner_id
    // NULL là "mẫu hệ thống" (đối tác chỉ được chọn, chỉ super_admin sửa/xoá), dòng có partner_id là
    // của riêng đối tác đó tự tạo. Toàn bộ dữ liệu hiện có giữ partner_id NULL = mẫu hệ thống.
    public function up(): void
    {
        foreach (['time_slots', 'tags'] as $table) {
            if (Schema::hasColumn($table, 'partner_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->uuid('partner_id')->nullable()->after('id');
                $t->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['time_slots', 'tags'] as $table) {
            if (! Schema::hasColumn($table, 'partner_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['partner_id']);
                $t->dropColumn('partner_id');
            });
        }
    }
};
