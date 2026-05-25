<?php

namespace Modules\AppPage\App\Policies;

use App\Models\User;
use Modules\AppPage\App\Models\AppPage;
use Illuminate\Auth\Access\HandlesAuthorization;

class AppPagePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_app::page');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AppPage $appPage): bool
    {
        return $user->can('view_app::page');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_app::page');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AppPage $appPage): bool
    {
        return $user->can('update_app::page');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AppPage $appPage): bool
    {
        return $user->can('delete_app::page');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_app::page');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, AppPage $appPage): bool
    {
        return $user->can('force_delete_app::page');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_app::page');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, AppPage $appPage): bool
    {
        return $user->can('restore_app::page');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_app::page');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, AppPage $appPage): bool
    {
        return $user->can('replicate_app::page');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_app::page');
    }
}
