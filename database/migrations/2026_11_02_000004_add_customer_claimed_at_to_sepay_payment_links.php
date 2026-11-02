<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Khách bấm "Tôi đã chuyển khoản" ở yêu cầu thanh toán của đối tác "xác nhận thủ công": ghi lúc khách báo để đối tác biết khoản nào cần đối chiếu trước
// và để giữ đơn lâu hơn trong lúc chờ đối tác xác nhận.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sepay_payment_links', function (Blueprint $table) {
            $table->timestamp('customer_claimed_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('sepay_payment_links', function (Blueprint $table) {
            $table->dropColumn('customer_claimed_at');
        });
    }
};
