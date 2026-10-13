<?php

namespace App\Policies;

use App\Models\MiniGame;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MiniGamePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_mini::game');
    }

    public function view(User $user, MiniGame $miniGame): bool
    {
        return $user->can('view_mini::game');
    }

    public function create(User $user): bool
    {
        return $user->can('create_mini::game');
    }

    public function update(User $user, MiniGame $miniGame): bool
    {
        return $user->can('update_mini::game');
    }

    public function delete(User $user, MiniGame $miniGame): bool
    {
        return $user->can('delete_mini::game');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_mini::game');
    }
}
