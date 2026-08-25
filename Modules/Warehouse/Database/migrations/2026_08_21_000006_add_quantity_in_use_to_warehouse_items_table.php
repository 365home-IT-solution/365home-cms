<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép ghi lại, TRONG CÙNG 1 vật tư, phần nào của tồn kho tổng (quantity) đang được SỬ DỤNG
// và phần nào còn DỰ PHÒNG — vd Nệm trắng tổng tồn 10, 5 đang dùng + 5 dự phòng. "Dự phòng" KHÔNG
// lưu cột riêng — luôn tính = quantity - quantity_in_use để không bao giờ lệch với tổng tồn thật.
// Đây là số liệu NHẬP TAY để theo dõi/báo cáo (không tự động theo phiếu nhập/xuất/kiểm kê — các
// phiếu đó chỉ thay đổi tổng "quantity", người dùng tự cập nhật lại phần đang dùng khi cần).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('warehouse_items', 'quantity_in_use')) {
            return;
        }

        Schema::table('warehouse_items', function (Blueprint $table) {
            $table->decimal('quantity_in_use', 15, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('warehouse_items', 'quantity_in_use')) {
            return;
        }

        Schema::table('warehouse_items', function (Blueprint $table) {
            $table->dropColumn('quantity_in_use');
        });
    }
};
