<?php

namespace Modules\Warehouse\App\Policies;

use App\Models\User;
use Modules\Warehouse\App\Models\WarehouseStockReturn;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehouseStockReturnPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_warehouse::stock::return');
    }

    public function view(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('view_warehouse::stock::return');
    }

    public function create(User $user): bool
    {
        return $user->can('create_warehouse::stock::return');
    }

    /**
     * CHỈ người đã TẠO phiếu mới được sửa — cùng nguyên tắc ở WarehouseStockOutPolicy::update().
     */
    public function update(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('update_warehouse::stock::return')
            && $warehouseStockReturn->created_by === $user->id;
    }

    public function delete(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('delete_warehouse::stock::return')
            && $warehouseStockReturn->created_by === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_warehouse::stock::return');
    }

    public function forceDelete(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('force_delete_warehouse::stock::return');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_warehouse::stock::return');
    }

    public function restore(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('restore_warehouse::stock::return');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_warehouse::stock::return');
    }

    public function replicate(User $user, WarehouseStockReturn $warehouseStockReturn): bool
    {
        return $user->can('replicate_warehouse::stock::return');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_warehouse::stock::return');
    }
}
