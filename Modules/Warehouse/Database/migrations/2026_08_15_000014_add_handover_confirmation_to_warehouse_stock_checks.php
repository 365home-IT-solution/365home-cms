<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Xác nhận bàn giao" — công ty vận hành theo ca (nhân viên dọn phòng/quản lý kho), cuối ca người
// trước tạo phiếu kiểm kê, đầu ca người sau cần đếm lại 1 lần nữa để xác nhận tồn kho bàn giao đúng.
// CHỈ áp dụng cho phiếu kiểm kê (không áp dụng nhập/xuất kho). Đây là bước XÁC MINH/ĐỐI CHIẾU thuần
// tuý — KHÔNG tự động điều chỉnh lại warehouse_items.quantity (nếu phát hiện lệch thật, người dùng
// cần tự tạo 1 phiếu kiểm kê MỚI để điều chỉnh đúng quy trình đã có, tránh 2 nguồn cùng âm thầm sửa
// tồn kho). Không bắt buộc — không có phiếu kiểm kê nào thì vẫn tạo phiếu mới bình thường.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stock_checks', function (Blueprint $table) {
            // null = chưa bàn giao; 'confirmed' = ca sau xác nhận khớp; 'discrepancy' = ca sau đếm
            // lại thấy lệch so với phiếu gốc.
            $table->string('handover_status')->nullable()->after('note');
            $table->foreignUuid('handover_confirmed_by')->nullable()->after('handover_status')->constrained('users')->nullOnDelete();
            $table->timestamp('handover_confirmed_at')->nullable()->after('handover_confirmed_by');
            $table->text('handover_note')->nullable()->after('handover_confirmed_at');
        });

        Schema::table('warehouse_stock_check_items', function (Blueprint $table) {
            // Số lượng ca sau đếm lại được — NULL nếu dòng này chưa được xác nhận bàn giao.
            $table->decimal('handover_quantity', 15, 2)->nullable()->after('difference');
            // handover_quantity - actual_quantity (KHÁC với "difference" = actual - system, đây là
            // chênh lệch giữa 2 LẦN ĐẾM, không phải giữa đếm và hệ thống).
            $table->decimal('handover_difference', 15, 2)->nullable()->after('handover_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stock_check_items', function (Blueprint $table) {
            $table->dropColumn(['handover_quantity', 'handover_difference']);
        });

        Schema::table('warehouse_stock_checks', function (Blueprint $table) {
            $table->dropForeign(['handover_confirmed_by']);
            $table->dropColumn(['handover_status', 'handover_confirmed_by', 'handover_confirmed_at', 'handover_note']);
        });
    }
};
