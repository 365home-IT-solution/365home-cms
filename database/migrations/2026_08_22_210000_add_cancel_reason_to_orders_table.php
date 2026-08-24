<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Lý do khi đơn bị huỷ (status IN cancelled_payment/failed/refunded) — dùng cho Báo cáo
            // đặt phòng (tỷ lệ ĐP bị huỷ theo lý do). Không bắt buộc: đơn huỷ cũ/huỷ tự động (hết hạn
            // thanh toán) không có lý do sẽ rơi vào nhóm "other" khi tổng hợp báo cáo.
            $table->enum('cancel_reason', ['no_show', 'other'])->nullable()->after('order_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancel_reason');
        });
    }
};
