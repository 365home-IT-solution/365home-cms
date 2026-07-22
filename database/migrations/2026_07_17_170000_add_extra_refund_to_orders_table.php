<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Đơn ĐÃ paid nhưng admin sửa đơn (huỷ khung giờ/dịch vụ) làm giá GIẢM — trước đây chỉ bắn 1
    // Notification 1 lần cho admin ("vui lòng thông báo cho khách"), KHÔNG lưu lại bất kỳ đâu, nên
    // nếu admin bỏ lỡ thông báo thì mất dấu hoàn toàn khoản cần hoàn khách. Thêm field riêng
    // (extra_refund_*, song song extra_charge_* dùng cho chiều tăng) để tab "Thông tin thanh toán"
    // hiện panel "Hoàn tiền chưa xử lý" + admin xác nhận đã hoàn — xem
    // EditOrder::handlePriceDiff()/ExtraChargeService::markRefundAsDone().
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('extra_refund_amount')->nullable()->after('extra_charge_expired_at');
            $table->string('extra_refund_method')->nullable()->after('extra_refund_amount'); // 'cash' | 'transfer'
            $table->timestamp('extra_refund_paid_at')->nullable()->after('extra_refund_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'extra_refund_amount',
                'extra_refund_method',
                'extra_refund_paid_at',
            ]);
        });
    }
};
