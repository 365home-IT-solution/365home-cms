<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Mã không thuộc đối tác nào (mã điểm danh, voucher hạng... do hệ thống tự tạo) mà ghi "đối tác chịu" là sai: các nơi tạo mã không
    // ghi funded_by nên DB tự điền mặc định 'partner'. Chỉ đổi người chịu trên mã — đơn đã chốt hoa hồng giữ số đã chốt (discounts).
    // Phạm vi đối tác / chi nhánh của voucher tự tặng nằm ở App\Settings\VoucherScopeSettings (database/settings).
    public function up(): void
    {
        DB::table('coupons')->whereNull('partner_id')->where('funded_by', 'partner')->update(['funded_by' => 'platform']);
    }

    public function down(): void
    {
    }
};
