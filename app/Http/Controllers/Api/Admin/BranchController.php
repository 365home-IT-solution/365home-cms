<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;

class BranchController extends Controller
{
    /**
     * GET /api/admin/branches
     * Danh sách chi nhánh (chỉ lấy chi nhánh CHA — parent_id = null, category_type = 'product') —
     * dùng để admin có nhiều chi nhánh (hoặc super_admin quản lý mọi đối tác) chọn lọc trước khi
     * gọi GET /api/admin/rooms?categories={slug}.
     *
     * super_admin: thấy TẤT CẢ chi nhánh (mọi đối tác).
     * User thường: chỉ thấy chi nhánh thuộc đúng đối tác của mình (users.partner_id).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Category::query()
            ->whereNull('parent_id')
            ->where('category_type', 'product');

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        $branches = $query->orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json(['data' => $branches]);
    }
}
