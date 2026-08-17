<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SQL VIEW gộp lịch sử biến động tồn kho từ CẢ 4 nguồn (nhập / xuất / kiểm kê / điều chỉnh thủ
// công) thành 1 "sổ nhật ký" duy nhất — dùng cho tab "Lịch sử biến động" trên trang sửa Vật tư (xem
// WarehouseStockMovement, WarehouseItemResource\RelationManagers\MovementsRelationManager).
//
// "balance_after" (tồn sau biến động) tính bằng window function SUM() OVER (...) ngay trong VIEW —
// KHÔNG tính tay ở PHP (tránh N+1 query cho mỗi dòng). Sắp theo occurred_at (ngày chứng từ:
// received_at/issued_at/checked_at/created_at — ngày người dùng khai báo hoặc lúc điều chỉnh) rồi
// tới entry_created_at (lúc dòng thật sự được tạo trong hệ thống, độ chính xác micro giây — xem các
// bảng *_items và warehouse_item_adjustments) để có thứ tự ổn định khi nhiều dòng trùng đúng
// occurred_at (rất phổ biến vì occurred_at thường chỉ là 1 ngày do người dùng chọn). Yêu cầu MySQL
// 8.0+ (window function).
//
// VIEW chỉ đọc (SELECT), không phục vụ INSERT/UPDATE/DELETE qua Eloquent.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP VIEW IF EXISTS {$prefix}warehouse_stock_movements");

        DB::statement("
            CREATE VIEW {$prefix}warehouse_stock_movements AS
            SELECT
                m.id,
                m.type,
                m.document_code,
                m.warehouse_item_id,
                m.quantity_change,
                m.occurred_at,
                m.entry_created_at,
                m.note,
                m.created_by,
                m.partner_id,
                SUM(m.quantity_change) OVER (
                    PARTITION BY m.warehouse_item_id
                    ORDER BY m.occurred_at, m.entry_created_at, m.id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS balance_after
            FROM (
                SELECT
                    CONCAT('in-', wsii.id) AS id,
                    'in' AS type,
                    wsi.code AS document_code,
                    wsii.warehouse_item_id AS warehouse_item_id,
                    wsii.quantity AS quantity_change,
                    wsi.received_at AS occurred_at,
                    wsii.note AS note,
                    wsi.created_by AS created_by,
                    wsi.partner_id AS partner_id,
                    wsii.created_at AS entry_created_at
                FROM {$prefix}warehouse_stock_in_items wsii
                INNER JOIN {$prefix}warehouse_stock_ins wsi ON wsi.id = wsii.warehouse_stock_in_id

                UNION ALL

                SELECT
                    CONCAT('out-', wsoi.id) AS id,
                    'out' AS type,
                    wso.code AS document_code,
                    wsoi.warehouse_item_id AS warehouse_item_id,
                    -wsoi.quantity AS quantity_change,
                    wso.issued_at AS occurred_at,
                    wsoi.note AS note,
                    wso.created_by AS created_by,
                    wso.partner_id AS partner_id,
                    wsoi.created_at AS entry_created_at
                FROM {$prefix}warehouse_stock_out_items wsoi
                INNER JOIN {$prefix}warehouse_stock_outs wso ON wso.id = wsoi.warehouse_stock_out_id

                UNION ALL

                SELECT
                    CONCAT('check-', wsci.id) AS id,
                    'check' AS type,
                    wsc.code AS document_code,
                    wsci.warehouse_item_id AS warehouse_item_id,
                    wsci.difference AS quantity_change,
                    wsc.checked_at AS occurred_at,
                    wsci.note AS note,
                    wsc.created_by AS created_by,
                    wsc.partner_id AS partner_id,
                    wsci.created_at AS entry_created_at
                FROM {$prefix}warehouse_stock_check_items wsci
                INNER JOIN {$prefix}warehouse_stock_checks wsc ON wsc.id = wsci.warehouse_stock_check_id

                UNION ALL

                SELECT
                    CONCAT('adj-', wia.id) AS id,
                    'adjustment' AS type,
                    CONCAT('DC', LPAD(wia.id, 6, '0')) AS document_code,
                    wia.warehouse_item_id AS warehouse_item_id,
                    wia.difference AS quantity_change,
                    wia.created_at AS occurred_at,
                    wia.note AS note,
                    wia.created_by AS created_by,
                    wia.partner_id AS partner_id,
                    wia.created_at AS entry_created_at
                FROM {$prefix}warehouse_item_adjustments wia
            ) m
        ");
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP VIEW IF EXISTS {$prefix}warehouse_stock_movements");
    }
};
