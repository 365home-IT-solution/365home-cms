-- Thêm 1 BẢNG GIÁ MẪU HỆ THỐNG (partner_id NULL — mọi đối tác đều thấy và chọn được).
-- Sửa tên mẫu + danh sách khung giờ/giá ở 2 chỗ đánh dấu (1) và (2) rồi chạy cả file.
-- Bảng có tiền tố cms_ (DB_PREFIX). Khung giờ chưa có trong danh mục hệ thống sẽ được tạo mới;
-- khung giờ đã có (kể cả bị trùng nhiều dòng) thì dùng dòng có id nhỏ nhất.

START TRANSACTION;

-- (2) Danh sách khung giờ + giá của mẫu: start, end (H:i), over_night (0/1), price (VNĐ), thứ tự.
DROP TEMPORARY TABLE IF EXISTS tmp_tpl_slots;
CREATE TEMPORARY TABLE tmp_tpl_slots (s CHAR(5), e CHAR(5), o TINYINT, price BIGINT UNSIGNED, sort_order INT);
INSERT INTO tmp_tpl_slots (s, e, o, price, sort_order) VALUES
    ('09:10', '12:00', 0, 150000, 0),
    ('12:30', '15:20', 0, 150000, 1),
    ('15:50', '18:40', 0, 150000, 2),
    ('19:10', '22:00', 0, 180000, 3),
    ('22:30', '08:30', 1, 300000, 4);

-- Tạo khung giờ hệ thống còn thiếu.
INSERT INTO cms_time_slots (partner_id, start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT NULL, v.s, v.e,
       CONCAT(v.s, ' - ', v.e, IF(v.o = 1, ' (Qua đêm)', '')),
       v.o, 'time', NOW(), NOW()
FROM tmp_tpl_slots v
WHERE NOT EXISTS (
    SELECT 1 FROM cms_time_slots t
    WHERE t.partner_id IS NULL
      AND (t.type IS NULL OR t.type <> 'date')
      AND LEFT(t.start_time, 5) = v.s
      AND LEFT(t.end_time, 5) = v.e
);

-- (1) Tên + ghi chú của mẫu.
INSERT INTO cms_price_templates (partner_id, source_template_id, name, note, is_active, sort_order, created_at, updated_at)
VALUES (NULL, NULL, 'Giá ngày thường (5 khung giờ)', 'Mẫu hệ thống: 4 khung trong ngày + 1 khung qua đêm', 1, 0, NOW(), NOW());

SET @tpl_id = LAST_INSERT_ID();

INSERT INTO cms_price_template_slots (price_template_id, timeslot_id, price, checkin, checkout, over_night, sort_order, created_at, updated_at)
SELECT @tpl_id,
       (SELECT MIN(t.id) FROM cms_time_slots t
         WHERE t.partner_id IS NULL
           AND (t.type IS NULL OR t.type <> 'date')
           AND LEFT(t.start_time, 5) = v.s
           AND LEFT(t.end_time, 5) = v.e),
       v.price, NULL, NULL, v.o, v.sort_order, NOW(), NOW()
FROM tmp_tpl_slots v
ORDER BY v.sort_order;

DROP TEMPORARY TABLE IF EXISTS tmp_tpl_slots;

COMMIT;

-- Kiểm tra:
-- SELECT p.id, p.name, s.sort_order, t.label, s.price
-- FROM cms_price_templates p JOIN cms_price_template_slots s ON s.price_template_id = p.id
-- JOIN cms_time_slots t ON t.id = s.timeslot_id WHERE p.id = @tpl_id ORDER BY s.sort_order;
