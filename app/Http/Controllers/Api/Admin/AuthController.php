<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Category\Entities\Category;

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
            'partner_name'   => $user->partner?->name,
            'is_super_admin' => $user->isSuperAdmin(),
            'categories'     => $this->branchCategories($user),
        ];
    }

    // Danh sách chi nhánh (category gốc, category_type=product, parent_id=null) mà user được
    // phép xem — dùng để app lưu lại và lọc các API thống kê/dữ liệu khác theo chi nhánh. Kèm field
    // `children`: TOÀN BỘ khu vực con (mọi cấp, dạng cây lồng nhau) của từng chi nhánh — để FE có
    // luôn dữ liệu chi nhánh + khu vực con ngay từ lúc đăng nhập, không cần gọi thêm
    // GET /api/admin/categories/tree chỉ để lấy khu vực con.
    // super_admin: tất cả chi nhánh. Chủ đối tác (không có bản ghi branchPermissions riêng):
    // toàn bộ chi nhánh thuộc đối tác mình. Nhân viên: chỉ chi nhánh được cấp quyền.
    private function branchCategories(User $user): array
    {
        // Nguồn ID dùng chung với CategoryController::visibleCategoriesQuery() và
        // CustomerController (tự động gán chi nhánh gốc cho khách hàng lúc tạo) — xem
        // User::rootProductCategoryIds(). Đảm bảo 3 nơi này LUÔN cùng 1 phạm vi chi nhánh gốc.
        $rootIds = $user->rootProductCategoryIds();

        if (empty($rootIds)) {
            return [];
        }

        return Category::whereIn('id', $rootIds)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'sort_order'])
            ->map(fn (Category $c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'slug'       => $c->slug,
                'sort_order' => $c->sort_order,
                'children'   => $this->childBranches($c->id),
            ])->toArray();
    }

    // Khu vực con (mọi cấp, đệ quy theo parent_id) của 1 chi nhánh, dạng cây lồng nhau. KHÔNG lọc
    // lại theo cột partner_id của chính khu vực con — cột đó có thể lệch/null với dữ liệu tạo
    // trước khi CategoryObserver cascade partner_id xuống tới cấp con (xem CategoryObserver::saved()
    // và App\Http\Controllers\Api\Admin\CategoryController::visibleCategoriesQuery()), lọc trực
    // tiếp bằng cột đó sẽ ẩn mất khu vực con hợp lệ của chi nhánh.
    private function childBranches(int $parentId): array
    {
        return Category::where('parent_id', $parentId)
            ->where('category_type', 'product')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'sort_order'])
            ->map(fn (Category $c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'slug'       => $c->slug,
                'sort_order' => $c->sort_order,
                'children'   => $this->childBranches($c->id),
            ])->toArray();
    }
}
