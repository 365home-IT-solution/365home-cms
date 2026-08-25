<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Hoàn tiền TOÀN BỘ đơn khi huỷ (đơn đã 'paid'/'deposit') — khác extra_refund_* (chỉ dành cho
    // phần chênh lệch khi admin sửa đơn giảm giá, xem ExtraChargeService::markRefundAsDone()).
    // Không có API hoàn tiền thật qua PayOS nên đây chỉ ghi nhận admin đã hoàn tiền mặt/chuyển
    // khoản NGOÀI hệ thống — xem OrderPaymentController::refund().
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('refund_amount')->nullable()->after('extra_refund_paid_at');
            $table->string('refund_method')->nullable()->after('refund_amount'); // 'cash' | 'transfer'
            $table->string('refund_reason')->nullable()->after('refund_method');
            $table->timestamp('refunded_at')->nullable()->after('refund_reason');
            $table->unsignedBigInteger('refunded_by')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'refund_amount',
                'refund_method',
                'refund_reason',
                'refunded_at',
                'refunded_by',
            ]);
        });
    }
};
