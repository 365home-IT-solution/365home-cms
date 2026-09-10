<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

// Toàn bộ Resource MiniHouse dùng global scope ActiveBuildingScope để tự lọc theo toà nhà — NHƯNG
// scope đó CHỈ bật khi đang chạy trong đúng panel Filament minihouse-admin (xem
// ActiveBuildingScope::isPanelActive(), so sánh Filament::getCurrentPanel()->getId()). Gọi qua API
// (không đi qua Filament) thì scope đó luôn tắt — nếu không tự lọc lại ở đây, 1 tài khoản chỉ được
// gán quản lý 1 toà sẽ thấy/sửa được TOÀN BỘ toà nhà khác qua API, dù trong panel web thì không.
// Mọi controller MiniHouse PHẢI dùng trait này để tự áp lại đúng ranh giới building_id thay vì tin
// vào global scope.
trait ScopesToMinihouseBuilding
{
    /** @return array<int> */
    protected function permittedBuildingIds(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User ? $user->rootBuildingIds() : [];
    }

    protected function isBuildingAllowed(Request $request, ?int $buildingId): bool
    {
        if (blank($buildingId)) {
            return false;
        }

        return in_array($buildingId, $this->permittedBuildingIds($request), true);
    }

    // super_admin bypass qua Gate::before (giống hệt cách Filament Resource của MiniHouse đang
    // check quyền — xem AuthorizesByPermission) — KHÔNG dùng ->hasPermissionTo() (Spatie thuần) vì
    // cách đó không đi qua Gate::before nên super_admin sẽ bị chặn nhầm.
    protected function hasPermission(Request $request, string $permission): bool
    {
        $user = $request->user();

        return ($user instanceof User) && ($user->isSuperAdmin() || $user->can($permission));
    }
}
