<?php

namespace Modules\Product\App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Product\App\Models\ManualLockPassword;

class ManualLockPasswordPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_manual_lock_password');
    }

    public function view(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('view_manual_lock_password');
    }

    public function create(User $user): bool
    {
        return $user->can('create_manual_lock_password');
    }

    public function update(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('update_manual_lock_password');
    }

    public function delete(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('delete_manual_lock_password');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_manual_lock_password');
    }

    public function forceDelete(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('force_delete_manual_lock_password');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_manual_lock_password');
    }

    public function restore(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('restore_manual_lock_password');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_manual_lock_password');
    }

    public function replicate(User $user, ManualLockPassword $manualLockPassword): bool
    {
        return $user->can('replicate_manual_lock_password');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_manual_lock_password');
    }
}
