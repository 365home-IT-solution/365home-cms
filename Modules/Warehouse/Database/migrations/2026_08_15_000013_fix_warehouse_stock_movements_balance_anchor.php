<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SỬA LỖI: "balance_after" (Tồn sau biến động) trước đây chỉ là tổng CỘNG DỒN các biến động ĐÃ
// ĐƯỢC GHI SỔ (nhập/xuất/kiểm kê/điều chỉnh), tính từ 0 — KHÔNG cộng thêm phần tồn kho khởi tạo
// ban đầu lúc tạo vật tư (lúc đó không đi qua phiếu nào nên không có "biến động" nào được ghi).
// Hậu quả: cột này không khớp với tồn kho THẬT (warehouse_items.quantity), thậm chí ra số âm vô lý
// dù tồn kho thật đang dương — đã phát hiện qua ví dụ thực tế: vật tư có quantity=35 nhưng dòng mới
// nhất trong sổ lại hiện balance_after=5.
//
// Fix: neo balance_after vào ĐÚNG tồn kho thật hiện tại (wi.quantity — luôn là giá trị SAU biến
// động MỚI NHẤT), rồi tính LÙI dần cho các dòng cũ hơn bằng cách trừ đi tổng các biến động XẢY RA
// SAU dòng đó (window ORDER BY ... DESC, khung UNBOUNDED PRECEDING → 1 PRECEDING = "tất cả các dòng
// mới hơn dòng hiện tại"). Dòng mới nhất có khung rỗng (không có dòng nào mới hơn) → SUM trả NULL,
// COALESCE về 0 để balance_after = tồn kho thật, không bị lệch.
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
                wi.quantity - COALESCE(SUM(m.quantity_change) OVER (
                    PARTITION BY m.warehouse_item_id
                    ORDER BY m.occurred_at DESC, m.entry_created_at DESC, m.id DESC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                ), 0) AS balance_after
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
            INNER JOIN {$prefix}warehouse_items wi ON wi.id = m.warehouse_item_id
        ");
    }

    public function down(): void
    {
        $prefix = DB::getTablePrefix();

        DB::statement("DROP VIEW IF EXISTS {$prefix}warehouse_stock_movements");
    }
};
