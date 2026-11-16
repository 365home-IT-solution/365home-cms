<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function permissions(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureSuperAdmin($user);
        $permissions = Permission::query()->where('guard_name', 'web')->get();

        return response()->json([
            'permissions' => collect($permissions)->sortBy('name')->values()->map(fn ($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'group' => $this->permissionGroup($permission->name),
            ]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureSuperAdmin($request->user());

        $roles = $this->visibleRoles($request->user())
            ->with('permissions:id,name,guard_name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['roles' => $roles->map(fn (Role $role) => $this->formatRole($role))]);
    }

    public function show(Request $request, int $role): JsonResponse
    {
        $this->ensureSuperAdmin($request->user());
        $model = $this->findVisibleRole($request->user(), $role);

        return response()->json(['role' => $this->formatRole($model->load('permissions')->loadCount('users'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureSuperAdmin($user);
        $data = $this->validateRole($request);
        $this->ensurePermissionsAreGrantable($user, $data['permissions'] ?? []);

        $role = DB::transaction(function () use ($data, $user) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'created_by' => $user->id,
            ]);
            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });

        return response()->json(['role' => $this->formatRole($role->load('permissions')->loadCount('users'))], 201);
    }

    public function update(Request $request, int $role): JsonResponse
    {
        $user = $request->user();
        $this->ensureSuperAdmin($user);
        $model = $this->findVisibleRole($user, $role);
        $this->ensureMutable($model);
        $data = $this->validateRole($request, $model);

        if (array_key_exists('permissions', $data)) {
            $this->ensurePermissionsAreGrantable($user, $data['permissions']);
        }

        DB::transaction(function () use ($data, $model) {
            if (array_key_exists('name', $data)) {
                $model->update(['name' => $data['name']]);
            }
            if (array_key_exists('permissions', $data)) {
                $model->syncPermissions($data['permissions']);
            }
        });

        return response()->json(['role' => $this->formatRole($model->fresh()->load('permissions')->loadCount('users'))]);
    }

    public function destroy(Request $request, int $role): JsonResponse
    {
        $user = $request->user();
        $this->ensureSuperAdmin($user);
        $model = $this->findVisibleRole($user, $role);
        $this->ensureMutable($model);

        if ($model->users()->exists()) {
            return response()->json(['message' => 'Không thể xoá vai trò đang được gán cho tài khoản.'], 422);
        }

        $model->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Đã xoá vai trò.']);
    }

    public function syncUserRoles(Request $request, string $user): JsonResponse
    {
        $actor = $request->user();
        $this->ensureSuperAdmin($actor);
        $target = $this->visibleUsers($actor)->find($user);

        if (! $target) {
            return response()->json(['message' => 'Không tìm thấy tài khoản.'], 404);
        }

        $data = $request->validate(['role_ids' => ['required', 'array'], 'role_ids.*' => ['integer', 'distinct']]);
        $roles = $this->visibleRoles($actor)->whereIn('id', $data['role_ids'])->get();

        if ($roles->count() !== count($data['role_ids'])) {
            return response()->json(['message' => 'Có vai trò không tồn tại hoặc bạn không được phép gán.'], 422);
        }

        $target->syncRoles($roles);

        return response()->json(['user' => [
            'id' => $target->id,
            'fullname' => $target->fullname,
            'email' => $target->email,
            'roles' => $target->roles()->get(['roles.id', 'roles.name']),
            'permissions' => $target->getAllPermissions()->pluck('name')->sort()->values(),
        ]]);
    }

    private function visibleRoles(User $user): Builder
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', config('filament-shield.panel_user.name'))
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('created_by', $user->id));
    }

    private function visibleUsers(User $user): Builder
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', config('filament-shield.super_admin.name')))
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('partner_id', $user->getRawOriginal('partner_id')));
    }

    private function findVisibleRole(User $user, int $id): Role
    {
        $role = $this->visibleRoles($user)->find($id);
        abort_if(! $role, 404, 'Không tìm thấy vai trò.');

        return $role;
    }

    private function validateRole(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [$role ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)->where('guard_name', 'web')],
            'permissions' => [$role ? 'sometimes' : 'required', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);
    }

    private function ensurePermissionsAreGrantable(User $user, array $permissions): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }
        $allowed = $user->getAllPermissions()->pluck('name');
        abort_if(collect($permissions)->diff($allowed)->isNotEmpty(), 403, 'Bạn không thể cấp quyền mà chính mình không có.');
    }

    private function ensureSuperAdmin(User $user): void
    {
        abort_unless($user->isSuperAdmin(), 403, 'Chỉ Super Admin mới được sử dụng API phân quyền.');
    }

    private function ensureMutable(Role $role): void
    {
        abort_if(in_array($role->name, [config('filament-shield.super_admin.name'), config('filament-shield.panel_user.name')], true), 422, 'Không thể sửa hoặc xoá vai trò hệ thống.');
    }

    private function formatRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
            'users_count' => $role->users_count ?? null,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }

    private function permissionGroup(string $name): string
    {
        return str_starts_with($name, 'page_') ? 'page' : (str_starts_with($name, 'widget_') ? 'widget' : (str_contains($name, '_') ? 'resource' : 'custom'));
    }
}
