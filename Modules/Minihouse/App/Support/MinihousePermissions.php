<?php

namespace Modules\Minihouse\App\Support;

use Illuminate\Database\Eloquent\Builder;

// NGUỒN DUY NHẤT liệt kê toàn bộ quyền panel MiniHouse — dùng chung bởi
// database\seeders\MinihousePermissionSeeder (tạo quyền/vai trò mặc định) VÀ
// Modules\Minihouse\App\Filament\Resources\RoleResource (checkbox chọn quyền khi tạo/sửa vai trò
// ngay trong panel MiniHouse) — tránh 2 nơi liệt kê tay dễ lệch nhau khi thêm Resource mới.
//
// LƯU Ý: SurchargeResource cố ý dùng CHUNG nhóm quyền 'buildings' (không có 'surcharges' riêng ở
// đây) — xem SurchargeResource::permissionGroup(), phụ thu là dữ liệu phụ trợ theo toà nhà.
class MinihousePermissions
{
    public const RESOURCE_GROUPS = [
        'buildings', 'rooms', 'tenants', 'contracts', 'invoices', 'transactions', 'reminders', 'residence_declarations',
    ];

    public const RESOURCE_ACTIONS = ['view_any', 'create', 'update', 'delete'];

    // Quyền không theo khuôn CRUD ở trên — điều kiện đăng nhập được panel (access_minihouse), xem
    // báo cáo (view_any_reports), xem nhật ký hoạt động (view_any_activity_logs — CHỈ xem, không có
    // create/update/delete vì ActivityLogResource là dữ liệu chỉ đọc, không ai được sửa/xoá log).
    public const EXTRA_PERMISSIONS = ['access_minihouse', 'view_any_reports', 'view_any_activity_logs'];

    public const GROUP_LABELS = [
        'buildings'              => 'Toà nhà / Phụ thu',
        'rooms'                  => 'Phòng',
        'tenants'                => 'Khách thuê',
        'contracts'              => 'Hợp đồng',
        'invoices'               => 'Hoá đơn',
        'transactions'           => 'Thu chi',
        'reminders'              => 'Nhắc việc',
        'residence_declarations' => 'Khai báo lưu trú',
    ];

    public const ACTION_LABELS = [
        'view_any' => 'Xem',
        'create'   => 'Tạo',
        'update'   => 'Sửa',
        'delete'   => 'Xoá',
    ];

    /** @return array<string> */
    public static function all(): array
    {
        $permissions = self::EXTRA_PERMISSIONS;

        foreach (self::RESOURCE_GROUPS as $group) {
            foreach (self::RESOURCE_ACTIONS as $action) {
                $permissions[] = "{$action}_{$group}";
            }
        }

        return $permissions;
    }

    // "Xem"/"Tạo"/"Sửa"/"Xoá" — dùng cho CheckboxList của 1 nhóm resource trong RoleForm.
    /** @return array<string, string> permission => nhãn */
    public static function optionsForGroup(string $group): array
    {
        $options = [];

        foreach (self::RESOURCE_ACTIONS as $action) {
            $options["{$action}_{$group}"] = self::ACTION_LABELS[$action];
        }

        return $options;
    }

    // Thu hẹp 1 query User bất kỳ về đúng "tài khoản thuộc MiniHouse" — có quyền access_minihouse
    // trực tiếp, qua vai trò, hoặc super_admin. Dùng chung bởi UserResource::getEloquentQuery()
    // (danh sách tài khoản hiện trong panel) VÀ ReminderNotificationService (ai được nhận thông báo
    // nhắc việc) — 1 nguồn duy nhất định nghĩa "tài khoản MiniHouse là gì", tránh 2 nơi định nghĩa
    // lệch nhau. Nhận vào Builder có sẵn (thay vì tự tạo User::query()) để gọi được ở
    // getEloquentQuery() (đã có parent::getEloquentQuery() làm gốc) lẫn 1 query mới tinh.
    public static function scopeToMinihouseUsers(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('permissions', fn (Builder $q2) => $q2->where('name', 'access_minihouse'))
                ->orWhereHas('roles.permissions', fn (Builder $q2) => $q2->where('name', 'access_minihouse'))
                ->orWhereHas('roles', fn (Builder $q2) => $q2->where('name', config('filament-shield.super_admin.name')));
        });
    }
}
