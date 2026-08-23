<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lịch đặt phòng (Book.php::buildCategoryData) lọc orderItems theo
// product_id IN (...) + checkout_date > ? + checkin_date <= ? để tính trạng thái
// đã đặt/còn trống cho từng ô — trước đây không có index nào khớp bộ lọc này
// (chỉ có index trên order_id), buộc phải quét toàn bảng, chậm dần khi order_items tăng.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items') && ! $this->indexExists('order_items_availability_index')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->index(['product_id', 'checkin_date', 'checkout_date'], 'order_items_availability_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && $this->indexExists('order_items_availability_index')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropIndex('order_items_availability_index');
            });
        }
    }

    protected function indexExists(string $indexName): bool
    {
        // DB::table()->getConnection()->getTablePrefix() để khớp đúng tên bảng thật trên DB (vd.
        // "cms_order_items" khi cấu hình có prefix "cms_") — information_schema không tự cộng
        // prefix như Schema::table()/hasTable() làm.
        $table = DB::getTablePrefix() . 'order_items';

        $row = DB::selectOne(
            'SELECT COUNT(1) AS cnt FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return $row && $row->cnt > 0;
    }
};
