<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phân biệt 2 nguồn gắn coupon mẫu vào 1 hạng thành viên — dùng để 2 khu vực cấu hình độc lập
// trên form Hạng thành viên (Filament) không ghi đè lẫn nhau khi lưu:
//   - 'auto'   → sinh ra từ Repeater "Coupon tự động cấp" (nhiều voucher/hạng, luồng chính thức).
//   - 'manual' → gắn tay ở "Mã giảm giá gắn thêm cho hạng" (mã có sẵn, dùng cứu cháy/ngoại lệ).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_tier_coupon', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('membership_tier_coupon', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
