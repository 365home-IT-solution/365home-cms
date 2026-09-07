<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Building;

// Toà nhà — xem Modules\Minihouse\App\Filament\Resources\BuildingResource cho bản Filament tương
// ứng. API này CHỈ phục vụ App\Models\User nội bộ (nhân viên/quản lý), không có khái niệm khách
// hàng — cùng auth:sanctum + admin.api với toàn bộ app/Http/Controllers/Api/Admin/*.
class BuildingController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/buildings?search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_buildings')) {
            return response()->json(['message' => 'Không có quyền xem toà nhà.'], 403);
        }

        $buildings = Building::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $this->permittedBuildingIds($request))
            ->withCount('rooms')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->string('search') . '%'))
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 20));

        $buildings->getCollection()->transform(fn (Building $b) => $this->toListItem($b));

        return response()->json($buildings);
    }

    // GET /api/admin/minihouse/buildings/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($building)]);
    }

    // POST /api/admin/minihouse/buildings
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_buildings')) {
            return response()->json(['message' => 'Không có quyền tạo toà nhà.'], 403);
        }

        $data = $request->validate([
            'name'                 => 'required|string|max:255',
            'address'              => 'nullable|string|max:255',
            'province'             => 'nullable|string|max:255',
            'ward'                 => 'nullable|string|max:255',
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'note'                 => 'nullable|string',
        ]);

        $building = Building::create($data);

        // Tài khoản thường (không phải super_admin) tạo toà nhà mới nhưng CHƯA được gán quản lý toà
        // đó — sẽ không thấy lại được nó ở chính API này ngay sau khi tạo (đúng theo đúng ranh giới
        // quyền, không phải bug) — cảnh báo rõ trong response để client tự xử lý (VD tự gán lại).
        $user = $request->user();
        $note = ($user && ! $user->isSuperAdmin() && ! in_array($building->id, $user->rootBuildingIds(), true))
            ? 'Toà nhà đã tạo nhưng tài khoản này chưa được gán quản lý — cần admin gán lại ở trang Tài khoản mới thấy được toà này.'
            : null;

        return response()->json(array_filter([
            'data'  => $this->toDetailItem($building),
            'notice' => $note,
        ]), 201);
    }

    // PUT/PATCH /api/admin/minihouse/buildings/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $data = $request->validate([
            'name'                 => 'sometimes|required|string|max:255',
            'address'              => 'nullable|string|max:255',
            'province'             => 'nullable|string|max:255',
            'ward'                 => 'nullable|string|max:255',
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
            'note'                 => 'nullable|string',
        ]);

        $building->update($data);

        return response()->json(['data' => $this->toDetailItem($building->fresh())]);
    }

    // DELETE /api/admin/minihouse/buildings/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_buildings') || ! $this->isBuildingAllowed($request, $id)) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building = Building::withoutGlobalScopes()->find($id);

        if (! $building) {
            return response()->json(['message' => 'Không tìm thấy toà nhà.'], 404);
        }

        $building->delete();

        return response()->json(['message' => 'Đã xoá toà nhà.']);
    }

    private function toListItem(Building $building): array
    {
        return [
            'id'                   => $building->id,
            'name'                 => $building->name,
            'address'              => $building->address,
            'rooms_count'          => $building->rooms_count,
            'electric_unit_price'  => $building->electric_unit_price,
            'water_unit_price'     => $building->water_unit_price,
        ];
    }

    private function toDetailItem(Building $building): array
    {
        return [
            'id'                   => $building->id,
            'name'                 => $building->name,
            'address'              => $building->address,
            'province'             => $building->province,
            'ward'                 => $building->ward,
            'electric_unit_price'  => $building->electric_unit_price,
            'water_unit_price'     => $building->water_unit_price,
            'note'                 => $building->note,
            'image'                => $building->image,
            'created_at'           => $building->created_at?->toIso8601String(),
            'updated_at'           => $building->updated_at?->toIso8601String(),
        ];
    }
}
