<?php

namespace Modules\Warehouse\App\Policies;

use App\Models\User;
use Modules\Warehouse\App\Models\WarehouseStockOut;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehouseStockOutPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_warehouse::stock::out');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('view_warehouse::stock::out');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_warehouse::stock::out');
    }

    /**
     * Determine whether the user can update the model.
     *
     * CHỈ người đã TẠO phiếu mới được sửa (kể cả có quyền update_warehouse::stock::out) — tránh
     * nhân viên khác tự ý đổi thông tin phiếu của nhau, dễ quy trách nhiệm hơn khi có sai lệch.
     * super_admin luôn bypass toàn bộ policy này qua Gate::before của Filament Shield, không bị
     * chặn bởi điều kiện created_by.
     */
    public function update(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('update_warehouse::stock::out')
            && $warehouseStockOut->created_by === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Cùng nguyên tắc "chỉ người tạo mới được sửa" ở update() — xem giải thích đầy đủ ở đó.
     */
    public function delete(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('delete_warehouse::stock::out')
            && $warehouseStockOut->created_by === $user->id;
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_warehouse::stock::out');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('force_delete_warehouse::stock::out');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_warehouse::stock::out');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('restore_warehouse::stock::out');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_warehouse::stock::out');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, WarehouseStockOut $warehouseStockOut): bool
    {
        return $user->can('replicate_warehouse::stock::out');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_warehouse::stock::out');
    }
}
