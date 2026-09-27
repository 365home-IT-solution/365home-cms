<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\WarehouseCategory;

// Nhóm vật tư — DÙNG CHUNG mọi Toà nhà (không lọc theo building_id) — mirror
// App\Http\Controllers\Api\Admin\WarehouseCategoryController (Home), bỏ tầng đối tác.
class WarehouseCategoryController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-categories
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem nhóm vật tư.'], 403);
        }

        return response()->json([
            'data' => WarehouseCategory::orderBy('name')->get(),
        ]);
    }

    // POST /api/admin/minihouse/warehouse-categories
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo nhóm vật tư.'], 403);
        }

        $data = $request->validate(['name' => 'required|string|max:150']);

        $category = WarehouseCategory::create($data);

        return response()->json(['data' => $category], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-categories/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa nhóm vật tư.'], 403);
        }

        $category = WarehouseCategory::find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate(['name' => 'required|string|max:150']);

        $category->update($data);

        return response()->json(['data' => $category]);
    }

    // DELETE /api/admin/minihouse/warehouse-categories/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá nhóm vật tư.'], 403);
        }

        $category = WarehouseCategory::find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $category->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }
}
