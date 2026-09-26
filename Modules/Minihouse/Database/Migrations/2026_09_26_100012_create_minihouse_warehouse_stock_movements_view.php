<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// "Sổ kho" (kardex) — VIEW chỉ đọc, ghép 5 nguồn biến động (nhập/xuất/kiểm kê/hoàn trả/điều chỉnh
// tay) thành 1 dòng thời gian, mirror ĐÚNG công thức đã sửa bên Home (migration
// fix_warehouse_stock_movements_balance_anchor + add_return_type — viết thẳng bản ĐÃ SỬA ở đây,
// không lặp lại bug ban đầu của Home): "balance_after" neo vào cột "quantity" SỐNG của
// minihouse_warehouse_items rồi TRỪ NGƯỢC các biến động xảy ra SAU mỗi dòng (ORDER BY ... DESC +
// ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) — không phải cộng dồn từ 0. Cần MySQL 8.0+
// (window function).
//
// Có thêm "building_id" (lấy từ chính vật tư) so với bản Home — MiniHouse lọc theo Toà nhà nên VIEW
// này cần sẵn cột đó để API/Filament lọc thẳng, không phải join lại.
return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP VIEW IF EXISTS {$prefix}minihouse_warehouse_stock_movements");

        DB::statement("
            CREATE VIEW {$prefix}minihouse_warehouse_stock_movements AS
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
                wi.building_id AS building_id,
                wi.quantity - COALESCE(SUM(m.quantity_change) OVER (
                    PARTITION BY m.warehouse_item_id
                    ORDER BY m.occurred_at DESC, m.entry_created_at DESC, m.id DESC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                ), 0) AS balance_after
            FROM (
                SELECT
                    CONCAT('in-', wsii.id) AS id, 'in' AS type, wsi.code AS document_code,
                    wsii.warehouse_item_id AS warehouse_item_id, wsii.quantity AS quantity_change,
                    wsi.received_at AS occurred_at, wsii.note AS note, wsi.created_by AS created_by,
                    wsii.created_at AS entry_created_at
                FROM {$prefix}minihouse_warehouse_stock_in_items wsii
                INNER JOIN {$prefix}minihouse_warehouse_stock_ins wsi ON wsi.id = wsii.warehouse_stock_in_id

                UNION ALL

                SELECT
                    CONCAT('out-', wsoi.id) AS id, 'out' AS type, wso.code AS document_code,
                    wsoi.warehouse_item_id AS warehouse_item_id, -wsoi.quantity AS quantity_change,
                    wso.issued_at AS occurred_at, wsoi.note AS note, wso.created_by AS created_by,
                    wsoi.created_at AS entry_created_at
                FROM {$prefix}minihouse_warehouse_stock_out_items wsoi
                INNER JOIN {$prefix}minihouse_warehouse_stock_outs wso ON wso.id = wsoi.warehouse_stock_out_id

                UNION ALL

                SELECT
                    CONCAT('return-', wsri.id) AS id, 'return' AS type, wsr.code AS document_code,
                    wsri.warehouse_item_id AS warehouse_item_id, wsri.quantity AS quantity_change,
                    wsr.returned_at AS occurred_at, wsri.note AS note, wsr.created_by AS created_by,
                    wsri.created_at AS entry_created_at
                FROM {$prefix}minihouse_warehouse_stock_return_items wsri
                INNER JOIN {$prefix}minihouse_warehouse_stock_returns wsr ON wsr.id = wsri.warehouse_stock_return_id

                UNION ALL

                SELECT
                    CONCAT('check-', wsci.id) AS id, 'check' AS type, wsc.code AS document_code,
                    wsci.warehouse_item_id AS warehouse_item_id, wsci.difference AS quantity_change,
                    wsc.checked_at AS occurred_at, wsci.note AS note, wsc.created_by AS created_by,
                    wsci.created_at AS entry_created_at
                FROM {$prefix}minihouse_warehouse_stock_check_items wsci
                INNER JOIN {$prefix}minihouse_warehouse_stock_checks wsc ON wsc.id = wsci.warehouse_stock_check_id

                UNION ALL

                SELECT
                    CONCAT('adj-', wia.id) AS id, 'adjustment' AS type,
                    CONCAT('DC', LPAD(wia.id, 6, '0')) AS document_code,
                    wia.warehouse_item_id AS warehouse_item_id, wia.difference AS quantity_change,
                    wia.created_at AS occurred_at, wia.note AS note, wia.created_by AS created_by,
                    wia.created_at AS entry_created_at
                FROM {$prefix}minihouse_warehouse_item_adjustments wia
            ) m
            INNER JOIN {$prefix}minihouse_warehouse_items wi ON wi.id = m.warehouse_item_id
        ");
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP VIEW IF EXISTS {$prefix}minihouse_warehouse_stock_movements");
    }
};
