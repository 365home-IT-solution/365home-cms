<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Amenity;

// Danh mục Tiện ích — dữ liệu DÙNG CHUNG cho mọi toà nhà (không có building_id), không cần lọc theo
// quyền quản lý toà — chỉ cần có quyền 'rooms' (Amenity dùng chung nhóm quyền với Phòng, giống bản
// Filament — xem AmenityResource::permissionGroup()).
class AmenityController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/amenities
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_rooms')) {
            return response()->json(['message' => 'Không có quyền xem tiện ích.'], 403);
        }

        return response()->json([
            'data' => Amenity::query()->orderBy('name')->get()->map(fn (Amenity $a) => $this->toItem($a)),
        ]);
    }

    // POST /api/admin/minihouse/amenities
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_rooms')) {
            return response()->json(['message' => 'Không có quyền tạo tiện ích.'], 403);
        }

        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'image' => 'nullable|string|max:2048',
        ]);

        $amenity = Amenity::create($data);

        return response()->json(['data' => $this->toItem($amenity)], 201);
    }

    // PUT/PATCH /api/admin/minihouse/amenities/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_rooms')) {
            return response()->json(['message' => 'Không có quyền sửa tiện ích.'], 403);
        }

        $amenity = Amenity::find($id);

        if (! $amenity) {
            return response()->json(['message' => 'Không tìm thấy tiện ích.'], 404);
        }

        $data = $request->validate([
            'name'  => 'sometimes|required|string|max:255',
            'image' => 'nullable|string|max:2048',
        ]);

        $amenity->update($data);

        return response()->json(['data' => $this->toItem($amenity)]);
    }

    // DELETE /api/admin/minihouse/amenities/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_rooms')) {
            return response()->json(['message' => 'Không có quyền xoá tiện ích.'], 403);
        }

        $amenity = Amenity::find($id);

        if (! $amenity) {
            return response()->json(['message' => 'Không tìm thấy tiện ích.'], 404);
        }

        $amenity->delete();

        return response()->json(['message' => 'Đã xoá tiện ích.']);
    }

    private function toItem(Amenity $amenity): array
    {
        return ['id' => $amenity->id, 'name' => $amenity->name, 'image' => $amenity->image];
    }
}
