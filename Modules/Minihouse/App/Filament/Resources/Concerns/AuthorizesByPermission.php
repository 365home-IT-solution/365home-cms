<?php

namespace Modules\Minihouse\App\Filament\Resources\Concerns;

// Phân quyền dùng CHUNG User/Spatie Permission với Home (xem MinihousePermissionSeeder) — mỗi
// Resource dùng trait này chỉ cần khai báo permissionGroup() (vd 'rooms') để có đủ 4 quyền
// view_any/create/update/delete_{group}, quản lý qua đúng trang "Vai trò" (Shield) của Home.
//
// Dùng ->can() (qua Gate) chứ KHÔNG dùng ->hasPermissionTo() (Spatie thuần) — super_admin của Home
// chỉ bypass được permission check khi đi qua Gate::before (xem config/filament-shield.php
// super_admin.define_via_gate), giống hệt cách PriceBoardResource đang làm.
trait AuthorizesByPermission
{
    abstract public static function permissionGroup(): string;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_' . static::permissionGroup()) ?? false);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('create_' . static::permissionGroup()) ?? false);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('update_' . static::permissionGroup()) ?? false);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('delete_' . static::permissionGroup()) ?? false);
    }
}
