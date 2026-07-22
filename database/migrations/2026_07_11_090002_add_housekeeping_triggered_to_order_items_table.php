<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'housekeeping_triggered')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('housekeeping_triggered')->default(false)->after('checkout_notified')
                ->comment('Đã chuyển phòng sang trạng thái "đang dọn vệ sinh" khi hết giờ đặt của đơn này chưa');
        });

        // Backfill BẮT BUỘC: nếu không đánh dấu các đơn ĐÃ hết giờ từ TRƯỚC KHI tính năng này tồn
        // tại là "đã xử lý", lần đầu command housekeeping:mark-cleaning chạy sẽ quét luôn toàn bộ
        // lịch sử booking cũ (hàng nghìn đơn) và chuyển nhầm mọi phòng có đơn `paid/deposit/shipped`
        // đã hết giờ — kể cả đơn từ nhiều tháng trước, dù phòng đó đã được dọn/sử dụng lại nhiều
        // lần từ đó tới nay — sang trạng thái "đang dọn vệ sinh" một cách sai lệch. Chỉ những đơn
        // hết giờ SAU thời điểm migrate này mới cần tự động kích hoạt dọn vệ sinh.
        DB::table('order_items')
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '<=', now())
            ->update(['housekeeping_triggered' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_items', 'housekeeping_triggered')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('housekeeping_triggered');
        });
    }
};
