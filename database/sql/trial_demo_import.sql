-- =====================================================================================
-- DỮ LIỆU MẪU CHO KHÁCH DÙNG THỬ (đối tác Homestay demo)
-- Gồm: đối tác + tài khoản đăng nhập + vai trò giới hạn quyền, 2 chi nhánh, 4 phòng,
--      tiện ích, dịch vụ, khung giờ + giá, điều kiện giảm giá, 14 đơn, mã cổng thủ công.
--
-- Đăng nhập:  dungthu@365home.vn  /  Demo@2026     (đổi ở mục 1 nếu cần)
-- Chạy 1 lần. Muốn nhập lại: chạy trial_demo_cleanup.sql trước.
-- SAU KHI NHẬP phải chạy:  php artisan permission:cache-reset
-- =====================================================================================

SET NAMES utf8mb4;
START TRANSACTION;

SET @d   := CURDATE();
SET @now := NOW();

-- -------------------------------------------------------------------------------------
-- 1. ĐỐI TÁC + TÀI KHOẢN CHỦ ĐỐI TÁC
--    verification_status phải là 'approved' thì tài khoản mới đăng nhập được trang quản trị.
-- -------------------------------------------------------------------------------------
INSERT INTO cms_partners
    (id, name, partner_type, representative_name, legal_name, phone, email, address,
     status, verification_status, verified_at, commission_rate, count_in_platform_stats, created_at, updated_at)
VALUES
    ('9e3650de-0000-4000-8000-000000000001', 'Demo Homestay (Dùng thử)', 'homestay', 'Khách dùng thử',
     'Demo Homestay (Dùng thử)', '0900000365', 'dungthu@365home.vn', '12 Nguyễn Văn Cừ, Ninh Kiều, Cần Thơ',
     1, 'approved', @now, '20', 0, @now, @now);   -- count_in_platform_stats = 0: không cộng vào thống kê của Super Admin

INSERT INTO cms_users
    (id, fullname, email, partner_id, email_verified_at, password, created_at, updated_at)
VALUES
    ('9e3650de-0000-4000-8000-0000000000a1', 'Khách dùng thử', 'dungthu@365home.vn',
     '9e3650de-0000-4000-8000-000000000001', @now,
     '$2y$12$zYemKcxUiLd3vJgljuX3.ePKsmaWyeItzNNEcm2v.8M9ZJIkZ063u',   -- Demo@2026
     @now, @now);

-- -------------------------------------------------------------------------------------
-- 2. VAI TRÒ "Dùng thử" — chỉ cấp đúng các tính năng khách cần trải nghiệm
-- -------------------------------------------------------------------------------------
SET @admin_id := (
    SELECT mhr.model_id
    FROM cms_model_has_roles mhr
    JOIN cms_roles r ON r.id = mhr.role_id
    WHERE r.name = 'super_admin'
    ORDER BY mhr.model_id
    LIMIT 1
);

INSERT INTO cms_roles (name, guard_name, created_by, created_at, updated_at)
VALUES ('Dùng thử - Demo Homestay', 'web', @admin_id, @now, @now);
SET @role_id := LAST_INSERT_ID();

INSERT INTO cms_role_has_permissions (permission_id, role_id)
SELECT p.id, @role_id
FROM cms_permissions p
WHERE p.guard_name = 'web'
  AND (
        -- Bảng điều khiển, trang Khóa cổng (mã cổng thủ công), hồ sơ cá nhân
        p.name IN ('page_Dashboard', 'page_GateLockManagement', 'page_MyProfilePage',
                   'widget_ManualLockPasswordTableWidget',
                   'view_room::type', 'view_any_room::type')
        -- category = Chi nhánh | product = Phòng | room::amenity(+assign) = Tiện ích
        -- room::service + addition::service = Dịch vụ | order = Đơn
        -- book = Lịch đặt + "Hệ thống giá" (giá phòng, điều kiện giảm giá)
        -- manual::lock::password = Mã cổng thủ công
     OR p.name REGEXP '^(view|view_any|create|update|delete|delete_any|reorder|replicate)_(category|product|room::amenity|room::amenity::assign|room::service|addition::service|order|book|manual::lock::password)$'
  );

INSERT INTO cms_model_has_roles (role_id, model_type, model_id)
VALUES (@role_id, 'App\\Models\\User', '9e3650de-0000-4000-8000-0000000000a1');

-- -------------------------------------------------------------------------------------
-- 3. CHI NHÁNH (categories loại 'product', parent_id NULL)
--    status = 1: chi nhánh hoạt động và HIỂN THỊ CÔNG KHAI trên web/app khách hàng.
-- -------------------------------------------------------------------------------------
INSERT INTO cms_categories
    (name, slug, description, checkin_time, checkout_time, status, category_type, partner_id, sort_order, created_at, updated_at)
VALUES
    ('Demo Homestay - Trung tâm', 'demo-trial-trung-tam', 'Chi nhánh mẫu số 1 (dữ liệu dùng thử).', '14:00:00', '12:00:00',
     1, 'product', '9e3650de-0000-4000-8000-000000000001', 900, @now, @now),
    ('Demo Homestay - Ven sông', 'demo-trial-ven-song', 'Chi nhánh mẫu số 2 (dữ liệu dùng thử).', '14:00:00', '12:00:00',
     1, 'product', '9e3650de-0000-4000-8000-000000000001', 901, @now, @now);

SET @b1 := (SELECT id FROM cms_categories WHERE slug = 'demo-trial-trung-tam');
SET @b2 := (SELECT id FROM cms_categories WHERE slug = 'demo-trial-ven-song');

-- -------------------------------------------------------------------------------------
-- 4. PHÒNG (products)
--    styles = 1: bán theo khung giờ (giá nằm ở cms_room_time_slots, mục 7)
--    styles = 2: bán theo ngày/đêm (giá nằm ở cột price)
--    bulk_discount_rules / full_booking_discount / room_config = "điều kiện giảm giá" + phụ thu
-- -------------------------------------------------------------------------------------
SET @room_type := (SELECT id FROM cms_room_types WHERE slug = 'homestay' LIMIT 1);

INSERT INTO cms_products
    (id, partner_id, sort_order, name, slug, type, bed_type, room_area_sqm, room_type_id,
     description, short_description, price, wifi, has_manual_lock, address, hotline,
     is_in_stock, housekeeping_status, is_activated,
     full_booking_discount, bulk_discount_rules, default_checkin, default_checkout,
     deposit_1_night, deposit_multi_night, deposit_min_nights, room_config, styles, nights,
     created_at, updated_at)
VALUES
    ('01kdem00000000000000000001', '9e3650de-0000-4000-8000-000000000001', 1, 'Sunrise 101', 'demo-trial-sunrise-101',
     'simple', '1 giường đôi', 22.00, @room_type,
     '<p>Phòng hướng đông đón nắng sớm, có ban công nhỏ và máy chiếu.</p>', '<p>Phòng đôi, ban công, máy chiếu.</p>',
     0, 'Demo_Sunrise / 12345678', 1, '12 Nguyễn Văn Cừ, Ninh Kiều, Cần Thơ', '0900000365',
     1, 'available', 1,
     '10%', '[{"slots":2,"discount":5},{"slots":3,"discount":10},{"slots":4,"discount":15}]', NULL, NULL,
     100, 50, 2, '{"max_free_guests":2,"extra_guest_fee":50000}', 1, 0, @now, @now),

    ('01kdem00000000000000000002', '9e3650de-0000-4000-8000-000000000001', 2, 'Moonlight 102', 'demo-trial-moonlight-102',
     'simple', '1 giường king', 28.00, @room_type,
     '<p>Phòng tông tối ấm cúng, bồn tắm và dàn loa bluetooth.</p>', '<p>Phòng king, bồn tắm, loa bluetooth.</p>',
     0, 'Demo_Moonlight / 12345678', 1, '12 Nguyễn Văn Cừ, Ninh Kiều, Cần Thơ', '0900000365',
     1, 'available', 1,
     '50000', '[{"slots":2,"discount":5},{"slots":3,"discount":10}]', NULL, NULL,
     100, 50, 2, '{"max_free_guests":2,"extra_guest_fee":70000}', 1, 0, @now, @now),

    ('01kdem00000000000000000003', '9e3650de-0000-4000-8000-000000000001', 3, 'Garden 201', 'demo-trial-garden-201',
     'simple', '1 giường đôi', 24.00, @room_type,
     '<p>Phòng nhìn ra vườn, yên tĩnh, có bếp mini.</p>', '<p>Phòng đôi, view vườn, bếp mini.</p>',
     0, 'Demo_Garden / 12345678', 1, '88 Bùi Hữu Nghĩa, Bình Thủy, Cần Thơ', '0900000365',
     1, 'available', 1,
     NULL, '[{"slots":2,"discount":5}]', NULL, NULL,
     100, 50, 2, '{"max_free_guests":2,"extra_guest_fee":50000}', 1, 0, @now, @now),

    ('01kdem00000000000000000004', '9e3650de-0000-4000-8000-000000000001', 4, 'Family Suite 202', 'demo-trial-family-suite-202',
     'simple', '2 giường đôi', 40.00, @room_type,
     '<p>Căn gia đình 2 giường đôi, bán theo đêm, có bếp và phòng khách riêng.</p>', '<p>Căn gia đình 4 khách, bán theo đêm.</p>',
     650000, 'Demo_Family / 12345678', 1, '88 Bùi Hữu Nghĩa, Bình Thủy, Cần Thơ', '0900000365',
     1, 'available', 1,
     NULL, NULL, '14:00:00', '12:00:00',
     100, 50, 2, '{"max_free_guests":4,"extra_guest_fee":100000}', 2, 0, @now, @now);

-- Gắn phòng vào chi nhánh (phòng không gắn chi nhánh sẽ KHÔNG hiển thị ở bất kỳ đâu)
INSERT INTO cms_categorizables (categorizable_type, categorizable_id, category_id, created_at, updated_at)
VALUES
    ('Modules\\Product\\App\\Models\\Product', '01kdem00000000000000000001', @b1, @now, @now),
    ('Modules\\Product\\App\\Models\\Product', '01kdem00000000000000000002', @b1, @now, @now),
    ('Modules\\Product\\App\\Models\\Product', '01kdem00000000000000000003', @b2, @now, @now),
    ('Modules\\Product\\App\\Models\\Product', '01kdem00000000000000000004', @b2, @now, @now);

-- -------------------------------------------------------------------------------------
-- 5. TIỆN ÍCH PHÒNG + gán cho cả 4 phòng
-- -------------------------------------------------------------------------------------
INSERT INTO cms_room_amenities (partner_id, amenity_type, icon, name, status, sort_order, created_at, updated_at)
VALUES
    ('9e3650de-0000-4000-8000-000000000001', 'Phòng tắm', 'water-outline', 'Vòi sen nóng lạnh', 1, 0, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Phòng tắm', 'hair-dryer-outline', 'Máy sấy tóc', 1, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Giải trí', 'tv', 'Smart TV 50 inch', 1, 0, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Giải trí', 'netflix', 'Netflix', 1, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Giải trí', 'wifi', 'WiFi tốc độ cao', 1, 2, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Hệ thống sưởi và làm mát', 'snowflake', 'Điều hòa 2 chiều', 1, 0, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Phòng ngủ và giặt ủi', 'iron-outline', 'Bàn là', 1, 0, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'An ninh gia đình', 'lock', 'Khóa cửa mật mã', 1, 0, @now, @now);

INSERT INTO cms_room_amenity_assigns (room_id, amenity_id)
SELECT p.id, a.id
FROM cms_products p
JOIN cms_room_amenities a ON a.partner_id = p.partner_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

-- -------------------------------------------------------------------------------------
-- 6. DỊCH VỤ
--    6a. Dịch vụ phòng (gắn riêng từng phòng, hiển thị ở trang chi tiết phòng)
--    6b. Dịch vụ thêm (bán kèm khi đặt đơn: nước, đồ ăn...) + gán cho cả 4 phòng
-- -------------------------------------------------------------------------------------
INSERT INTO cms_room_services (product_id, name, icon, description, price, unit, sort_order, created_at, updated_at)
VALUES
    ('01kdem00000000000000000001', 'Bữa sáng', 'coffee-outline', 'Bánh mì, trứng, cà phê', 60000, 'người/ngày', 0, @now, @now),
    ('01kdem00000000000000000001', 'Trang trí sinh nhật', 'sparkles-outline', 'Bóng bay, đèn, bảng tên', 250000, 'lần', 1, @now, @now),
    ('01kdem00000000000000000002', 'Bữa sáng', 'coffee-outline', 'Bánh mì, trứng, cà phê', 60000, 'người/ngày', 0, @now, @now),
    ('01kdem00000000000000000003', 'Thuê xe máy', 'airplane-outline', 'Xe tay ga, đã gồm xăng', 150000, 'ngày', 0, @now, @now),
    ('01kdem00000000000000000004', 'Bữa sáng', 'coffee-outline', 'Buffet sáng cho gia đình', 80000, 'người/ngày', 0, @now, @now),
    ('01kdem00000000000000000004', 'Đưa đón sân bay', 'airplane-outline', 'Xe 7 chỗ, 1 chiều', 350000, 'lượt', 1, @now, @now);

INSERT INTO cms_additional_services (partner_id, name, price, is_active, created_at, updated_at)
VALUES
    ('9e3650de-0000-4000-8000-000000000001', 'Nước suối', 10000, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Nước ngọt', 20000, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Mì ly', 20000, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Snack', 15000, 1, @now, @now),
    ('9e3650de-0000-4000-8000-000000000001', 'Bia lon', 25000, 1, @now, @now);

INSERT INTO cms_room_additional_service_assigns (room_id, additional_service_id)
SELECT p.id, s.id
FROM cms_products p
JOIN cms_additional_services s ON s.partner_id = p.partner_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

SET @sv_water := (SELECT id FROM cms_additional_services WHERE partner_id = '9e3650de-0000-4000-8000-000000000001' AND name = 'Nước suối' LIMIT 1);
SET @sv_beer  := (SELECT id FROM cms_additional_services WHERE partner_id = '9e3650de-0000-4000-8000-000000000001' AND name = 'Bia lon' LIMIT 1);

-- -------------------------------------------------------------------------------------
-- 7. KHUNG GIỜ + GIÁ THEO KHUNG GIỜ
--    cms_time_slots là danh mục DÙNG CHUNG toàn hệ thống (không có partner_id):
--    khung nào đã có sẵn (trùng giờ bắt đầu/kết thúc) thì dùng lại, chưa có mới thêm.
-- -------------------------------------------------------------------------------------
INSERT INTO cms_time_slots (start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT '08:00:00', '11:00:00', '08:00 - 11:00', 0, 'time', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_time_slots WHERE start_time = '08:00:00' AND end_time = '11:00:00');

INSERT INTO cms_time_slots (start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT '11:30:00', '14:30:00', '11:30 - 14:30', 0, 'time', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_time_slots WHERE start_time = '11:30:00' AND end_time = '14:30:00');

INSERT INTO cms_time_slots (start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT '15:00:00', '18:00:00', '15:00 - 18:00', 0, 'time', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_time_slots WHERE start_time = '15:00:00' AND end_time = '18:00:00');

INSERT INTO cms_time_slots (start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT '18:30:00', '21:30:00', '18:30 - 21:30', 0, 'time', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_time_slots WHERE start_time = '18:30:00' AND end_time = '21:30:00');

INSERT INTO cms_time_slots (start_time, end_time, label, over_night, type, created_at, updated_at)
SELECT '22:00:00', '07:30:00', '22:00 - 07:30 (Qua đêm)', 0, 'time', @now, @now FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cms_time_slots WHERE start_time = '22:00:00' AND end_time = '07:30:00');

SET @ts1 := (SELECT MIN(id) FROM cms_time_slots WHERE start_time = '08:00:00' AND end_time = '11:00:00');
SET @ts2 := (SELECT MIN(id) FROM cms_time_slots WHERE start_time = '11:30:00' AND end_time = '14:30:00');
SET @ts3 := (SELECT MIN(id) FROM cms_time_slots WHERE start_time = '15:00:00' AND end_time = '18:00:00');
SET @ts4 := (SELECT MIN(id) FROM cms_time_slots WHERE start_time = '18:30:00' AND end_time = '21:30:00');
SET @ts5 := (SELECT MIN(id) FROM cms_time_slots WHERE start_time = '22:00:00' AND end_time = '07:30:00');

-- Giá từng khung giờ của 3 phòng bán theo giờ (date NULL = giá áp dụng mọi ngày)
INSERT INTO cms_room_time_slots (room_id, timeslot_id, date, price, status, over_night, settings, created_at, updated_at)
VALUES
    ('01kdem00000000000000000001', @ts1, NULL, 150000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000001', @ts2, NULL, 150000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000001', @ts3, NULL, 170000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000001', @ts4, NULL, 190000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000001', @ts5, NULL, 350000, 'available', 1, '{"blocked_dates":[]}', @now, @now),

    ('01kdem00000000000000000002', @ts1, NULL, 180000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000002', @ts2, NULL, 180000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000002', @ts3, NULL, 200000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000002', @ts4, NULL, 220000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000002', @ts5, NULL, 420000, 'available', 1, '{"blocked_dates":[]}', @now, @now),

    ('01kdem00000000000000000003', @ts1, NULL, 160000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000003', @ts2, NULL, 160000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000003', @ts3, NULL, 180000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000003', @ts4, NULL, 200000, 'available', 0, '{"blocked_dates":[]}', @now, @now),
    ('01kdem00000000000000000003', @ts5, NULL, 380000, 'available', 1, '{"blocked_dates":[]}', @now, @now);

-- -------------------------------------------------------------------------------------
-- 8. ĐƠN ĐẶT PHÒNG (14 đơn, ngày tính tương đối theo hôm nay để bảng điều khiển có số liệu)
-- -------------------------------------------------------------------------------------
INSERT INTO cms_orders
    (partner_id, created_by, user_id, order_code, amount, full_amount, deposit_percent, deposit_paid_amount, money_deposit,
     deposit_paid_at, paid_at, refund_amount, refund_method, refund_reason, refunded_at,
     buyer_name, buyer_phone, payment_method, expired_at, status, guest_count, category_id, note_for_admin,
     checked_in_at, checked_out_at, order_status, created_at, updated_at)
VALUES
    -- 01: đã trả phòng, PayOS
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000001', 150000, 150000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 12 DAY, '07:35:00'), NULL, NULL, NULL, NULL,
     'Nguyễn Minh Anh', '0901000001', 'PayOS', NULL, 'paid', 2, @b1, NULL,
     TIMESTAMP(@d - INTERVAL 12 DAY, '08:02:00'), TIMESTAMP(@d - INTERVAL 12 DAY, '10:55:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 12 DAY, '07:30:00'), TIMESTAMP(@d - INTERVAL 12 DAY, '10:55:00')),
    -- 02: 2 khung giờ liền nhau, tiền mặt
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000002', 380000, 380000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 10 DAY, '11:20:00'), NULL, NULL, NULL, NULL,
     'Trần Quốc Bảo', '0901000002', 'cod', NULL, 'paid', 2, @b1, 'Khách quen, thanh toán tiền mặt tại quầy.',
     TIMESTAMP(@d - INTERVAL 10 DAY, '11:35:00'), TIMESTAMP(@d - INTERVAL 10 DAY, '17:50:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 10 DAY, '11:15:00'), TIMESTAMP(@d - INTERVAL 10 DAY, '17:50:00')),
    -- 03: qua đêm
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000003', 380000, 380000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 9 DAY, '20:10:00'), NULL, NULL, NULL, NULL,
     'Lê Thị Cẩm Tú', '0901000003', 'PayOS', NULL, 'paid', 2, @b2, NULL,
     TIMESTAMP(@d - INTERVAL 9 DAY, '22:05:00'), TIMESTAMP(@d - INTERVAL 8 DAY, '07:20:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 9 DAY, '20:05:00'), TIMESTAMP(@d - INTERVAL 8 DAY, '07:20:00')),
    -- 04: phòng theo ngày, 2 đêm
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000004', 1300000, 1300000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 8 DAY, '09:40:00'), NULL, NULL, NULL, NULL,
     'Phạm Gia Huy', '0901000004', 'PayOS', NULL, 'paid', 4, @b2, 'Gia đình 4 người, cần thêm 1 nệm phụ.',
     TIMESTAMP(@d - INTERVAL 7 DAY, '14:10:00'), TIMESTAMP(@d - INTERVAL 5 DAY, '11:45:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 8 DAY, '09:30:00'), TIMESTAMP(@d - INTERVAL 5 DAY, '11:45:00')),
    -- 05: hủy do không thanh toán
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000005', 170000, 170000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
     'Võ Hoàng Dũng', '0901000005', 'PayOS', TIMESTAMP(@d - INTERVAL 5 DAY, '13:15:00'), 'cancelled_payment', 2, @b1, NULL,
     NULL, NULL, 'pending',
     TIMESTAMP(@d - INTERVAL 5 DAY, '13:00:00'), TIMESTAMP(@d - INTERVAL 5 DAY, '13:15:00')),
    -- 06: có mua thêm dịch vụ (2 nước suối)
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000006', 210000, 210000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 4 DAY, '18:10:00'), NULL, NULL, NULL, NULL,
     'Đặng Thu Hà', '0901000006', 'cod', NULL, 'paid', 2, @b1, NULL,
     TIMESTAMP(@d - INTERVAL 4 DAY, '18:32:00'), TIMESTAMP(@d - INTERVAL 4 DAY, '21:25:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 4 DAY, '18:05:00'), TIMESTAMP(@d - INTERVAL 4 DAY, '21:25:00')),
    -- 07: đã hoàn tiền
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000007', 160000, 160000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 3 DAY, '07:10:00'),
     160000, 'bank_transfer', 'Khách báo bận đột xuất, hủy trước giờ nhận phòng.', TIMESTAMP(@d - INTERVAL 3 DAY, '07:50:00'),
     'Bùi Khánh Linh', '0901000007', 'PayOS', NULL, 'refunded', 2, @b2, NULL,
     NULL, NULL, 'pending',
     TIMESTAMP(@d - INTERVAL 3 DAY, '07:05:00'), TIMESTAMP(@d - INTERVAL 3 DAY, '07:50:00')),
    -- 08: qua đêm
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000008', 420000, 420000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 2 DAY, '19:30:00'), NULL, NULL, NULL, NULL,
     'Hồ Nhật Nam', '0901000008', 'PayOS', NULL, 'paid', 2, @b1, NULL,
     TIMESTAMP(@d - INTERVAL 2 DAY, '22:10:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '07:25:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 2 DAY, '19:25:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '07:25:00')),
    -- 09: 2 khung giờ, hôm qua
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000009', 300000, 300000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 1 DAY, '07:45:00'), NULL, NULL, NULL, NULL,
     'Ngô Bảo Trân', '0901000009', 'PayOS', NULL, 'paid', 2, @b1, NULL,
     TIMESTAMP(@d - INTERVAL 1 DAY, '08:05:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '14:20:00'), 'checked_out',
     TIMESTAMP(@d - INTERVAL 1 DAY, '07:40:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '14:20:00')),
    -- 10: đang lưu trú, mới đặt cọc 50% (phòng theo ngày, 2 đêm)
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000010', 1300000, 1300000, 50, 650000, 650000, TIMESTAMP(@d - INTERVAL 2 DAY, '10:05:00'), NULL, NULL, NULL, NULL, NULL,
     'Dương Thanh Phong', '0901000010', 'PayOS', NULL, 'deposit', 3, @b2, 'Đã cọc 50%, thu phần còn lại khi trả phòng.',
     TIMESTAMP(@d - INTERVAL 1 DAY, '14:15:00'), NULL, 'staying',
     TIMESTAMP(@d - INTERVAL 2 DAY, '10:00:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '14:15:00')),
    -- 11: nhận phòng tối nay
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000011', 190000, 190000, NULL, NULL, NULL, NULL, TIMESTAMP(@d, '06:20:00'), NULL, NULL, NULL, NULL,
     'Lý Hải Yến', '0901000011', 'PayOS', NULL, 'paid', 2, @b1, NULL,
     NULL, NULL, 'pending',
     TIMESTAMP(@d, '06:15:00'), TIMESTAMP(@d, '06:20:00')),
    -- 12: nhận phòng ngày mai, tiền mặt
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000012', 250000, 250000, NULL, NULL, NULL, NULL, TIMESTAMP(@d, '06:45:00'), NULL, NULL, NULL, NULL,
     'Trương Đức Thịnh', '0901000012', 'cod', NULL, 'paid', 2, @b1, 'Khách xin nhận phòng sớm 15 phút.',
     NULL, NULL, 'pending',
     TIMESTAMP(@d, '06:40:00'), TIMESTAMP(@d, '06:45:00')),
    -- 13: chờ thanh toán
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000013', 160000, 160000, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
     'Mai Phương Thảo', '0901000013', 'PayOS', @now + INTERVAL 1 DAY, 'pending', 2, @b2, NULL,
     NULL, NULL, 'pending',
     @now, @now),
    -- 14: qua đêm, 3 ngày nữa
    ('9e3650de-0000-4000-8000-000000000001', '9e3650de-0000-4000-8000-0000000000a1', '9e3650de-0000-4000-8000-0000000000a1',
     '99000000000014', 380000, 380000, NULL, NULL, NULL, NULL, TIMESTAMP(@d - INTERVAL 1 DAY, '21:10:00'), NULL, NULL, NULL, NULL,
     'Đỗ Anh Khoa', '0901000014', 'PayOS', NULL, 'paid', 2, @b2, NULL,
     NULL, NULL, 'pending',
     TIMESTAMP(@d - INTERVAL 1 DAY, '21:05:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '21:10:00'));

SET @o01 := (SELECT id FROM cms_orders WHERE order_code = '99000000000001');
SET @o02 := (SELECT id FROM cms_orders WHERE order_code = '99000000000002');
SET @o03 := (SELECT id FROM cms_orders WHERE order_code = '99000000000003');
SET @o04 := (SELECT id FROM cms_orders WHERE order_code = '99000000000004');
SET @o05 := (SELECT id FROM cms_orders WHERE order_code = '99000000000005');
SET @o06 := (SELECT id FROM cms_orders WHERE order_code = '99000000000006');
SET @o07 := (SELECT id FROM cms_orders WHERE order_code = '99000000000007');
SET @o08 := (SELECT id FROM cms_orders WHERE order_code = '99000000000008');
SET @o09 := (SELECT id FROM cms_orders WHERE order_code = '99000000000009');
SET @o10 := (SELECT id FROM cms_orders WHERE order_code = '99000000000010');
SET @o11 := (SELECT id FROM cms_orders WHERE order_code = '99000000000011');
SET @o12 := (SELECT id FROM cms_orders WHERE order_code = '99000000000012');
SET @o13 := (SELECT id FROM cms_orders WHERE order_code = '99000000000013');
SET @o14 := (SELECT id FROM cms_orders WHERE order_code = '99000000000014');

-- Chi tiết đơn: mỗi khung giờ đã đặt = 1 dòng
INSERT INTO cms_order_items
    (order_id, product_id, name, price, quantity, is_shipped, checkin_date, checkout_date, slot_label, over_night, extra_fee, guest_count, created_at, updated_at)
VALUES
    (@o01, '01kdem00000000000000000001', 'Sunrise 101 - 08:00 - 11:00', 150000, 1, 1,
     TIMESTAMP(@d - INTERVAL 12 DAY, '08:00:00'), TIMESTAMP(@d - INTERVAL 12 DAY, '11:00:00'), '08:00 - 11:00', 0, '0', 2, @now, @now),

    (@o02, '01kdem00000000000000000002', 'Moonlight 102 - 11:30 - 14:30', 180000, 1, 1,
     TIMESTAMP(@d - INTERVAL 10 DAY, '11:30:00'), TIMESTAMP(@d - INTERVAL 10 DAY, '14:30:00'), '11:30 - 14:30', 0, '0', 2, @now, @now),
    (@o02, '01kdem00000000000000000002', 'Moonlight 102 - 15:00 - 18:00', 200000, 1, 1,
     TIMESTAMP(@d - INTERVAL 10 DAY, '15:00:00'), TIMESTAMP(@d - INTERVAL 10 DAY, '18:00:00'), '15:00 - 18:00', 0, '0', 2, @now, @now),

    (@o03, '01kdem00000000000000000003', 'Garden 201 - 22:00 - 07:30 (Qua đêm)', 380000, 1, 1,
     TIMESTAMP(@d - INTERVAL 9 DAY, '22:00:00'), TIMESTAMP(@d - INTERVAL 8 DAY, '07:30:00'), '22:00 - 07:30 (Qua đêm)', 1, '0', 2, @now, @now),

    -- phòng theo ngày: mỗi đêm = 1 dòng
    (@o04, '01kdem00000000000000000004', CONCAT('Family Suite 202 - ', DATE_FORMAT(@d - INTERVAL 7 DAY, '%d/%m/%Y')), 650000, 1, 1,
     TIMESTAMP(@d - INTERVAL 7 DAY, '14:00:00'), TIMESTAMP(@d - INTERVAL 6 DAY, '12:00:00'), NULL, 0, '0', 4, @now, @now),
    (@o04, '01kdem00000000000000000004', CONCAT('Family Suite 202 - ', DATE_FORMAT(@d - INTERVAL 6 DAY, '%d/%m/%Y')), 650000, 1, 1,
     TIMESTAMP(@d - INTERVAL 6 DAY, '14:00:00'), TIMESTAMP(@d - INTERVAL 5 DAY, '12:00:00'), NULL, 0, '0', 4, @now, @now),

    (@o05, '01kdem00000000000000000001', 'Sunrise 101 - 15:00 - 18:00', 170000, 1, 1,
     TIMESTAMP(@d - INTERVAL 5 DAY, '15:00:00'), TIMESTAMP(@d - INTERVAL 5 DAY, '18:00:00'), '15:00 - 18:00', 0, '0', 2, @now, @now),

    (@o06, '01kdem00000000000000000001', 'Sunrise 101 - 18:30 - 21:30', 190000, 1, 1,
     TIMESTAMP(@d - INTERVAL 4 DAY, '18:30:00'), TIMESTAMP(@d - INTERVAL 4 DAY, '21:30:00'), '18:30 - 21:30', 0, '0', 2, @now, @now),

    (@o07, '01kdem00000000000000000003', 'Garden 201 - 08:00 - 11:00', 160000, 1, 1,
     TIMESTAMP(@d - INTERVAL 3 DAY, '08:00:00'), TIMESTAMP(@d - INTERVAL 3 DAY, '11:00:00'), '08:00 - 11:00', 0, '0', 2, @now, @now),

    (@o08, '01kdem00000000000000000002', 'Moonlight 102 - 22:00 - 07:30 (Qua đêm)', 420000, 1, 1,
     TIMESTAMP(@d - INTERVAL 2 DAY, '22:00:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '07:30:00'), '22:00 - 07:30 (Qua đêm)', 1, '0', 2, @now, @now),

    (@o09, '01kdem00000000000000000001', 'Sunrise 101 - 08:00 - 11:00', 150000, 1, 1,
     TIMESTAMP(@d - INTERVAL 1 DAY, '08:00:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '11:00:00'), '08:00 - 11:00', 0, '0', 2, @now, @now),
    (@o09, '01kdem00000000000000000001', 'Sunrise 101 - 11:30 - 14:30', 150000, 1, 1,
     TIMESTAMP(@d - INTERVAL 1 DAY, '11:30:00'), TIMESTAMP(@d - INTERVAL 1 DAY, '14:30:00'), '11:30 - 14:30', 0, '0', 2, @now, @now),

    (@o10, '01kdem00000000000000000004', CONCAT('Family Suite 202 - ', DATE_FORMAT(@d - INTERVAL 1 DAY, '%d/%m/%Y')), 650000, 1, 1,
     TIMESTAMP(@d - INTERVAL 1 DAY, '14:00:00'), TIMESTAMP(@d, '12:00:00'), NULL, 0, '0', 3, @now, @now),
    (@o10, '01kdem00000000000000000004', CONCAT('Family Suite 202 - ', DATE_FORMAT(@d, '%d/%m/%Y')), 650000, 1, 1,
     TIMESTAMP(@d, '14:00:00'), TIMESTAMP(@d + INTERVAL 1 DAY, '12:00:00'), NULL, 0, '0', 3, @now, @now),

    (@o11, '01kdem00000000000000000001', 'Sunrise 101 - 18:30 - 21:30', 190000, 1, 1,
     TIMESTAMP(@d, '18:30:00'), TIMESTAMP(@d, '21:30:00'), '18:30 - 21:30', 0, '0', 2, @now, @now),

    (@o12, '01kdem00000000000000000002', 'Moonlight 102 - 15:00 - 18:00', 200000, 1, 1,
     TIMESTAMP(@d + INTERVAL 1 DAY, '15:00:00'), TIMESTAMP(@d + INTERVAL 1 DAY, '18:00:00'), '15:00 - 18:00', 0, '0', 2, @now, @now),

    (@o13, '01kdem00000000000000000003', 'Garden 201 - 11:30 - 14:30', 160000, 1, 1,
     TIMESTAMP(@d + INTERVAL 2 DAY, '11:30:00'), TIMESTAMP(@d + INTERVAL 2 DAY, '14:30:00'), '11:30 - 14:30', 0, '0', 2, @now, @now),

    (@o14, '01kdem00000000000000000003', 'Garden 201 - 22:00 - 07:30 (Qua đêm)', 380000, 1, 1,
     TIMESTAMP(@d + INTERVAL 3 DAY, '22:00:00'), TIMESTAMP(@d + INTERVAL 4 DAY, '07:30:00'), '22:00 - 07:30 (Qua đêm)', 1, '0', 2, @now, @now);

-- Dịch vụ mua kèm trong đơn
INSERT INTO cms_order_services (order_id, service_id, service_name, price, quantity, subtotal, created_at, updated_at)
VALUES
    (@o06, @sv_water, 'Nước suối', 10000, 2, 20000, @now, @now),
    (@o12, @sv_beer,  'Bia lon',   25000, 2, 50000, @now, @now);

-- -------------------------------------------------------------------------------------
-- 9. MÃ CỔNG THỦ CÔNG (trang "Khóa cổng" > Khóa thủ công)
--    Không gắn phòng cụ thể  = áp dụng cho CẢ chi nhánh.
--    Có gắn phòng (bảng nối)  = chỉ áp dụng cho phòng đó.
-- -------------------------------------------------------------------------------------
INSERT INTO cms_manual_lock_passwords (name, gate_password, room_password, category_id, notes, valid_from, valid_until, is_active, created_at, updated_at)
VALUES
    ('Mã cổng chung - Trung tâm', '2468#', NULL, @b1, 'Mã cổng dùng chung cả chi nhánh trong tháng này.',
     TIMESTAMP(DATE_FORMAT(@d, '%Y-%m-01'), '00:00:00'), TIMESTAMP(LAST_DAY(@d), '23:59:59'), 1, @now, @now),
    ('Mã cổng chung - Ven sông', '1357#', NULL, @b2, 'Mã cổng dùng chung cả chi nhánh trong tháng này.',
     TIMESTAMP(DATE_FORMAT(@d, '%Y-%m-01'), '00:00:00'), TIMESTAMP(LAST_DAY(@d), '23:59:59'), 1, @now, @now),
    ('Sunrise 101 - hôm nay', '5566#', '1122', @b1, 'Mã riêng của phòng, đổi mỗi ngày.',
     TIMESTAMP(@d, '00:00:00'), TIMESTAMP(@d + INTERVAL 1 DAY, '12:00:00'), 1, @now, @now),
    ('Moonlight 102 - ngày mai', '7788#', '3344', @b1, 'Mã riêng của phòng, đổi mỗi ngày.',
     TIMESTAMP(@d + INTERVAL 1 DAY, '00:00:00'), TIMESTAMP(@d + INTERVAL 2 DAY, '12:00:00'), 1, @now, @now),
    ('Sunrise 101 - tuần trước (đã hết hạn)', '9090#', '0000', @b1, 'Ví dụ một bộ mã đã hết hạn.',
     TIMESTAMP(@d - INTERVAL 8 DAY, '00:00:00'), TIMESTAMP(@d - INTERVAL 7 DAY, '12:00:00'), 0, @now, @now);

SET @mlp_sunrise_today := (SELECT id FROM cms_manual_lock_passwords WHERE category_id = @b1 AND gate_password = '5566#' ORDER BY id DESC LIMIT 1);
SET @mlp_moon_tomorrow := (SELECT id FROM cms_manual_lock_passwords WHERE category_id = @b1 AND gate_password = '7788#' ORDER BY id DESC LIMIT 1);
SET @mlp_sunrise_old   := (SELECT id FROM cms_manual_lock_passwords WHERE category_id = @b1 AND gate_password = '9090#' ORDER BY id DESC LIMIT 1);

INSERT INTO cms_manual_lock_password_product (manual_lock_password_id, product_id)
VALUES
    (@mlp_sunrise_today, '01kdem00000000000000000001'),
    (@mlp_moon_tomorrow, '01kdem00000000000000000002'),
    (@mlp_sunrise_old,   '01kdem00000000000000000001');

COMMIT;

-- Kiểm tra nhanh sau khi nhập
SELECT 'chi nhánh' AS muc, COUNT(*) AS so_luong FROM cms_categories WHERE partner_id = '9e3650de-0000-4000-8000-000000000001'
UNION ALL SELECT 'phòng', COUNT(*) FROM cms_products WHERE partner_id = '9e3650de-0000-4000-8000-000000000001'
UNION ALL SELECT 'tiện ích', COUNT(*) FROM cms_room_amenities WHERE partner_id = '9e3650de-0000-4000-8000-000000000001'
UNION ALL SELECT 'dịch vụ thêm', COUNT(*) FROM cms_additional_services WHERE partner_id = '9e3650de-0000-4000-8000-000000000001'
UNION ALL SELECT 'giá khung giờ', COUNT(*) FROM cms_room_time_slots WHERE room_id LIKE '01kdem0000000000000000000_'
UNION ALL SELECT 'đơn', COUNT(*) FROM cms_orders WHERE partner_id = '9e3650de-0000-4000-8000-000000000001'
UNION ALL SELECT 'quyền của vai trò', COUNT(*) FROM cms_role_has_permissions WHERE role_id = @role_id;
