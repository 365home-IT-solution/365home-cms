<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lưu tạm số tiền giảm của TỪNG mã (code => discount_amount) ngay lúc áp mã — trước đây lượt dùng
// coupon bị trừ NGAY lúc tạo đơn nên số tiền giảm có sẵn trong cùng request, ghi thẳng vào
// coupon_usages luôn. Nay việc trừ lượt dời sang lúc thanh toán thành công (xem OrderObserver +
// CouponUsageLedger), nên cần "mang" số tiền giảm từ lúc áp mã tới tận lúc đó mới ghi log được.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'coupon_discount_amounts')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->json('coupon_discount_amounts')->nullable()->after('coupon_codes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('coupon_discount_amounts');
        });
    }
};
