<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Đối tác được dùng mã (so với products.partner_id của phòng) — cho voucher hệ thống tự tặng theo "Phạm vi voucher tự tặng"
    // (App\Support\VoucherScope). Không đổi đối tác thành danh sách chi nhánh vì có phòng chưa gắn chi nhánh nào (vd Solar Town) và
    // chi nhánh mở sau phải tự được tính. null / [] = không giới hạn theo đối tác. Xem Coupon::passesPartnerRestriction().
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'allowed_partner_ids')) {
                $table->json('allowed_partner_ids')->nullable()->after('partner_share_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (Schema::hasColumn('coupons', 'allowed_partner_ids')) {
                $table->dropColumn('allowed_partner_ids');
            }
        });
    }
};
