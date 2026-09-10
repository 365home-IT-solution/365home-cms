<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Surcharge;

// Danh mục Phụ thu theo từng toà nhà — dùng chung quyền 'buildings' (giống bản Filament, xem
// SurchargeResource::permissionGroup()).
class SurchargeController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/surcharges?building_id=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_buildings')) {
            return response()->json(['message' => 'Không có quyền xem phụ thu.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $surcharges = Surcharge::query()
            ->withoutGlobalScopes()
            ->with('building:id,name')
            ->whereIn('building_id', $permitted)
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->orderBy('building_id')
            ->paginate((int) $request->integer('per_page', 20));

        $surcharges->getCollection()->transform(fn (Surcharge $s) => $this->toItem($s));

        return response()->json($surcharges);
    }

    // POST /api/admin/minihouse/surcharges
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_buildings')) {
            return response()->json(['message' => 'Không có quyền tạo phụ thu.'], 403);
        }

        $data = $request->validate([
            'building_id' => 'required|integer|exists:minihouse_buildings,id',
            'name'        => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0',
            'note'        => 'nullable|string',
            'is_active'   => 'nullable|boolean',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phụ thu cho toà nhà này.'], 403);
        }

        $surcharge = Surcharge::create($data);

        return response()->json(['data' => $this->toItem($surcharge->fresh('building'))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/surcharges/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_buildings')) {
            return response()->json(['message' => 'Không có quyền sửa phụ thu.'], 403);
        }

        $surcharge = Surcharge::withoutGlobalScopes()->find($id);

        if (! $surcharge || ! $this->isBuildingAllowed($request, $surcharge->building_id)) {
            return response()->json(['message' => 'Không tìm thấy phụ thu.'], 404);
        }

        $data = $request->validate([
            'name'      => 'sometimes|required|string|max:255',
            'amount'    => 'sometimes|required|numeric|min:0',
            'note'      => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $surcharge->update($data);

        return response()->json(['data' => $this->toItem($surcharge->fresh('building'))]);
    }

    // DELETE /api/admin/minihouse/surcharges/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_buildings')) {
            return response()->json(['message' => 'Không có quyền xoá phụ thu.'], 403);
        }

        $surcharge = Surcharge::withoutGlobalScopes()->find($id);

        if (! $surcharge || ! $this->isBuildingAllowed($request, $surcharge->building_id)) {
            return response()->json(['message' => 'Không tìm thấy phụ thu.'], 404);
        }

        $surcharge->delete();

        return response()->json(['message' => 'Đã xoá phụ thu.']);
    }

    private function toItem(Surcharge $surcharge): array
    {
        return [
            'id'            => $surcharge->id,
            'building_id'   => $surcharge->building_id,
            'building_name' => $surcharge->building?->name,
            'name'          => $surcharge->name,
            'amount'        => $surcharge->amount,
            'note'          => $surcharge->note,
            'is_active'     => $surcharge->is_active,
        ];
    }
}
