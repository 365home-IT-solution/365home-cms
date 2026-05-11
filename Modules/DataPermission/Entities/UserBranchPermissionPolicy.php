<?php

namespace Modules\DataPermission\Entities;

use App\Models\User;
use Modules\DataPermission\Entities\UserBranchPermission;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserBranchPermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_user::branch::permission');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('view_user::branch::permission');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_user::branch::permission');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('update_user::branch::permission');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('delete_user::branch::permission');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_user::branch::permission');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('force_delete_user::branch::permission');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_user::branch::permission');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('restore_user::branch::permission');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_user::branch::permission');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, UserBranchPermission $userBranchPermission): bool
    {
        return $user->can('replicate_user::branch::permission');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_user::branch::permission');
    }
}
