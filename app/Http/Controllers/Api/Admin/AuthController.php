<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /api/admin/login
    // Đăng nhập cho tài khoản quản trị/nhân viên nội bộ (App\Models\User) — trả Sanctum token
    // để gọi các API /api/admin/... (middleware auth:sanctum + admin.api).
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Email hoặc mật khẩu không đúng.'], 401);
        }

        if (! $this->hasAdminAccess($user)) {
            return response()->json(['message' => 'Tài khoản không có quyền truy cập khu vực quản trị.'], 403);
        }

        $token = $user->createToken('admin-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
        ]);
    }

    // POST /api/admin/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    // GET /api/admin/me
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    // Chỉ user có ít nhất 1 role thật (không phải panel_user mặc định) mới được coi là admin/nhân viên nội bộ
    // — cùng logic với User::canAccessPanel().
    private function hasAdminAccess(User $user): bool
    {
        return $user->roles()
            ->where('name', '!=', config('filament-shield.panel_user.name'))
            ->exists();
    }

    private function formatUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'fullname'       => $user->fullname,
            'email'          => $user->email,
            'roles'          => $user->roles()->pluck('name'),
            'partner_id'     => $user->partner_id,
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }
}
