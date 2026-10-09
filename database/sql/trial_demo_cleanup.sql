-- =====================================================================================
-- XÓA TOÀN BỘ DỮ LIỆU MẪU do trial_demo_import.sql tạo ra (kể cả dữ liệu khách tự thêm
-- trong lúc dùng thử dưới đối tác demo này: phòng, đơn, mã cổng, tiện ích, dịch vụ...).
-- Không xóa cms_time_slots vì đó là danh mục dùng chung của toàn hệ thống.
-- SAU KHI CHẠY:  php artisan permission:cache-reset
-- =====================================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- Đơn
DELETE oi FROM cms_order_items oi
JOIN cms_orders o ON o.id = oi.order_id
WHERE o.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE os FROM cms_order_services os
JOIN cms_orders o ON o.id = os.order_id
WHERE o.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE FROM cms_orders WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';

-- Mã cổng thủ công (theo chi nhánh của đối tác demo)
DELETE mp FROM cms_manual_lock_password_product mp
JOIN cms_manual_lock_passwords m ON m.id = mp.manual_lock_password_id
JOIN cms_categories c ON c.id = m.category_id
WHERE c.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE m FROM cms_manual_lock_passwords m
JOIN cms_categories c ON c.id = m.category_id
WHERE c.partner_id = '9e3650de-0000-4000-8000-000000000001';

-- Giá khung giờ, dịch vụ, tiện ích gắn với phòng
DELETE rts FROM cms_room_time_slots rts
JOIN cms_products p ON p.id = rts.room_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE rs FROM cms_room_services rs
JOIN cms_products p ON p.id = rs.product_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE ra FROM cms_room_amenity_assigns ra
JOIN cms_products p ON p.id = ra.room_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE rsa FROM cms_room_additional_service_assigns rsa
JOIN cms_products p ON p.id = rsa.room_id
WHERE p.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE FROM cms_room_amenities      WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';
DELETE FROM cms_additional_services WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';

-- Phòng + liên kết phòng–chi nhánh
DELETE cz FROM cms_categorizables cz
JOIN cms_products p ON p.id = cz.categorizable_id
WHERE cz.categorizable_type = 'Modules\\Product\\App\\Models\\Product'
  AND p.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE FROM cms_products   WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';
DELETE FROM cms_categories WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';

-- Vai trò + tài khoản + đối tác
DELETE rp FROM cms_role_has_permissions rp
JOIN cms_roles r ON r.id = rp.role_id
WHERE r.name = 'Dùng thử - Demo Homestay' AND r.guard_name = 'web';

DELETE mr FROM cms_model_has_roles mr
JOIN cms_users u ON u.id = mr.model_id
WHERE u.partner_id = '9e3650de-0000-4000-8000-000000000001';

DELETE FROM cms_roles    WHERE name = 'Dùng thử - Demo Homestay' AND guard_name = 'web';
DELETE FROM cms_users    WHERE partner_id = '9e3650de-0000-4000-8000-000000000001';
DELETE FROM cms_partners WHERE id = '9e3650de-0000-4000-8000-000000000001';

COMMIT;
