<?php

namespace Modules\Motrak\App\Support;

use App\Models\User;

/**
 * Phân quyền GPS theo QUYỀN (permission) của hệ thống Vai trò & Phân quyền (CMS → Phân quyền → Vai trò, tab "Custom"). Super Admin luôn được.
 *
 *  view_gps     Xem dữ liệu GPS: tổng quan / bản đồ, danh sách thiết bị, vị trí, lịch sử, nhật ký đồng bộ.
 *  manage_gps   Cấu hình & vận hành: nhập Token, bật/tắt đồng bộ, đồng bộ tay, gán IMEI, ghép thiết bị với xe cho thuê. (Bao gồm view_gps.)
 *
 * Tương thích ngược: vai trò cũ "Quản lý GPS" (config motrak.viewer_role) vẫn xem được như trước.
 * Nhân viên thuê xe chỉ thấy GPS của các xe thuê qua màn hình thuê xe (có kiểm soát riêng), không được xem toàn bộ GPS.
 */
class MotrakAccess
{
    public const PERMISSIONS = [
        'view_gps'   => 'GPS — Xem bản đồ, thiết bị, lịch sử, nhật ký đồng bộ',
        'manage_gps' => 'GPS — Cấu hình Motrak (Token, đồng bộ, gán IMEI, ghép xe cho thuê)',
    ];

    public static function canConfigure(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->can('manage_gps'));
    }

    public static function canView(?User $user): bool
    {
        return $user !== null && (
            $user->isSuperAdmin() || $user->can('view_gps') || $user->can('manage_gps')
            || $user->hasRole((string) config('motrak.viewer_role', 'Quản lý GPS'))
        );
    }
}
