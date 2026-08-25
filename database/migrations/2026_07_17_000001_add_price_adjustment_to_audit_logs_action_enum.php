<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // AuditLogger::log(action: 'price_adjustment', ...) được gọi trong
        // EditOrder::handlePriceDiff() mỗi khi admin sửa đơn đã thanh toán/cọc làm giá thay đổi
        // (xem OrderForm.php: "Lịch sử thanh toán" đọc lại action này để hiện bước "Phát sinh
        // thêm"/"Hoàn tiền"). Nhưng cột 'action' chỉ khai báo enum('create','update','delete') —
        // insert 'price_adjustment' bị MySQL truncate/reject (Data truncated for column 'action'),
        // khiến TOÀN BỘ luồng lưu đơn + tạo QR phát sinh bị lỗi và rollback. Mở rộng enum để thêm
        // giá trị này.
        $table = DB::getTablePrefix() . 'audit_logs';

        DB::statement("ALTER TABLE `{$table}` MODIFY `action` ENUM('create', 'update', 'delete', 'price_adjustment') NOT NULL");
    }

    public function down(): void
    {
        $table = DB::getTablePrefix() . 'audit_logs';

        DB::statement("ALTER TABLE `{$table}` MODIFY `action` ENUM('create', 'update', 'delete') NOT NULL");
    }
};
