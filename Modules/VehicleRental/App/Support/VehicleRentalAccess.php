<?php

namespace Modules\VehicleRental\App\Support;

use App\Models\User;

/**
 * Phân quyền module thuê xe theo QUYỀN (permission) của hệ thống Vai trò & Phân quyền (CMS → Phân quyền → Vai trò, tab "Custom"). Super Admin luôn được.
 *
 *  Quyền                        Cho phép
 *  ---------------------------  ------------------------------------------------------------------------------------------
 *  view_rental_vehicles         Xem Đội xe (danh sách, chi tiết, gói giá, khoá xe, GPS của xe)
 *  manage_rental_vehicles       Thêm / sửa / xoá xe, gói giá, khoá xe, nút bật-tắt cho thuê, gắn thiết bị GPS
 *  view_rental_bookings         Xem Đơn thuê xe (danh sách, chi tiết, hành trình)
 *  operate_rental_bookings      Vận hành đơn: xác nhận thanh toán, giao / nhận xe, phụ phí, tất toán, huỷ, hoàn tiền, không đến nhận
 *  review_rental_documents      Xem và duyệt / từ chối giấy tờ của khách
 *  view_rental_schedule         Xem Lịch xe (Gantt)
 *  view_rental_gps_alerts       Xem và xử lý Cảnh báo GPS của xe thuê
 *  manage_rental_settings       Cài đặt thuê xe (công tắc hiển thị trên website)
 *
 * Quyền "manage" bao gồm quyền "view" tương ứng; "operate" bao gồm "view_rental_bookings".
 * Tương thích ngược: tài khoản có vai trò cũ "Nhân viên thuê xe" (config vehiclerental.staff_role) vẫn có các quyền vận hành quầy
 * (đơn, giấy tờ, lịch, cảnh báo) như trước dù chưa tích quyền — nhưng KHÔNG có quyền quản trị đội xe / cài đặt.
 */
class VehicleRentalAccess
{
    public const PERMISSIONS = [
        'view_rental_vehicles'    => 'Thuê xe — Xem đội xe',
        'manage_rental_vehicles'  => 'Thuê xe — Quản trị đội xe (thêm/sửa/xoá xe, gói giá, khoá xe, bật/tắt cho thuê)',
        'view_rental_bookings'    => 'Thuê xe — Xem đơn thuê',
        'operate_rental_bookings' => 'Thuê xe — Vận hành đơn (xác nhận thanh toán, giao/nhận xe, phụ phí, tất toán, huỷ, hoàn tiền)',
        'review_rental_documents' => 'Thuê xe — Duyệt giấy tờ của khách',
        'view_rental_schedule'    => 'Thuê xe — Xem lịch xe',
        'view_rental_gps_alerts'  => 'Thuê xe — Xem / xử lý cảnh báo GPS',
        'manage_rental_settings'  => 'Thuê xe — Cài đặt thuê xe (công tắc hiển thị trên website)',
    ];

    /** Quyền của vai trò cũ "Nhân viên thuê xe" (vận hành quầy). */
    public const STAFF_PERMISSIONS = ['view_rental_bookings', 'operate_rental_bookings', 'review_rental_documents', 'view_rental_schedule', 'view_rental_gps_alerts'];

    private static function can(?User $user, string ...$permissions): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        foreach ($permissions as $p) {
            if ($user->can($p)) {
                return true;
            }
        }

        return false;
    }

    /** Vai trò cũ "Nhân viên thuê xe": giữ quyền vận hành quầy như trước. */
    private static function legacyStaff(?User $user, string $permission): bool
    {
        return $user !== null && in_array($permission, self::STAFF_PERMISSIONS, true) && $user->hasRole((string) config('vehiclerental.staff_role', 'Nhân viên thuê xe'));
    }

    private static function check(?User $user, string $permission, string ...$implied): bool
    {
        return self::can($user, $permission, ...$implied) || self::legacyStaff($user, $permission);
    }

    public static function canManageVehicles(?User $user): bool
    {
        return self::can($user, 'manage_rental_vehicles');
    }

    public static function canViewVehicles(?User $user): bool
    {
        return self::can($user, 'view_rental_vehicles', 'manage_rental_vehicles');
    }

    public static function canViewBookings(?User $user): bool
    {
        return self::check($user, 'view_rental_bookings', 'operate_rental_bookings');
    }

    public static function canOperateBookings(?User $user): bool
    {
        return self::check($user, 'operate_rental_bookings');
    }

    public static function canReviewDocuments(?User $user): bool
    {
        return self::check($user, 'review_rental_documents');
    }

    public static function canViewSchedule(?User $user): bool
    {
        return self::check($user, 'view_rental_schedule');
    }

    public static function canViewGpsAlerts(?User $user): bool
    {
        return self::check($user, 'view_rental_gps_alerts');
    }

    public static function canManageSettings(?User $user): bool
    {
        return self::can($user, 'manage_rental_settings');
    }

    /** @deprecated Giữ cho mã cũ: quản trị đội xe. Dùng canManageVehicles(). */
    public static function isAdmin(?User $user): bool
    {
        return self::canManageVehicles($user);
    }

    /** @deprecated Giữ cho mã cũ: vận hành quầy. Dùng các hàm canXxx() cụ thể. */
    public static function isStaff(?User $user): bool
    {
        return self::canViewBookings($user) || self::canOperateBookings($user);
    }
}
