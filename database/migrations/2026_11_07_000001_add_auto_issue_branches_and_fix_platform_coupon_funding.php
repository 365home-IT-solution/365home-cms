<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 1. Mã điểm danh định kỳ của hạng thành viên được giới hạn theo đối tác / chi nhánh — trước đây luôn áp dụng mọi chi nhánh.
    //    auto_issue_partner_ids: mọi chi nhánh của các đối tác này; auto_issue_category_ids: thu hẹp về đúng các chi nhánh này.
    //    Cả hai rỗng / null = mọi chi nhánh (giữ hành vi cũ). Xem MembershipService::checkinRewardCategoryIds().
    // 2. Mã không thuộc đối tác nào (mã định kỳ, voucher hạng... do hệ thống tự tạo) mà ghi "đối tác chịu" là sai: các nơi tạo mã không
    //    ghi funded_by nên DB tự điền mặc định 'partner'. Chỉ đổi người chịu trên mã — đơn đã chốt hoa hồng giữ số đã chốt (discounts).
    public function up(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('membership_tiers', 'auto_issue_partner_ids')) {
                $table->json('auto_issue_partner_ids')->nullable()->after('auto_issue_coupon_usage_limit');
            }
            if (! Schema::hasColumn('membership_tiers', 'auto_issue_category_ids')) {
                $table->json('auto_issue_category_ids')->nullable()->after('auto_issue_partner_ids');
            }
        });

        DB::table('coupons')->whereNull('partner_id')->where('funded_by', 'partner')->update(['funded_by' => 'platform']);
    }

    public function down(): void
    {
        Schema::table('membership_tiers', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(['auto_issue_partner_ids', 'auto_issue_category_ids'],
                fn (string $column) => Schema::hasColumn('membership_tiers', $column))));
        });
    }
};
