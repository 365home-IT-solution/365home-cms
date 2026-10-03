<?php

namespace App\Policies;

use App\Models\Camera;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

// Phân quyền Camera theo quyền Shield. Camera dùng chung cho 2 panel nên chấp nhận cả 2 cách đặt tên quyền:
// Homestay: view_any_camera, create_camera, ...  |  MiniHouse: view_any_cameras, create_cameras, ... (super_admin được Shield cho qua).
// Trước đây KHÔNG có policy nên mọi tài khoản đều thấy và sửa được camera dù không được tích quyền.
class CameraPolicy
{
    use HandlesAuthorization;

    private function allows(User $user, string $action): bool
    {
        return $user->can("{$action}_camera") || $user->can("{$action}_cameras");
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view_any');
    }

    public function view(User $user, Camera $camera): bool
    {
        return $this->allows($user, 'view') || $this->allows($user, 'view_any');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Camera $camera): bool
    {
        return $this->allows($user, 'update');
    }

    public function delete(User $user, Camera $camera): bool
    {
        return $this->allows($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'delete_any') || $this->allows($user, 'delete');
    }
}
