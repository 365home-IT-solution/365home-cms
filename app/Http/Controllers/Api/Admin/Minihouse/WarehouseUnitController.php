<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\WarehouseUnit;

// Đơn vị tính vật tư — mirror App\Http\Controllers\Api\Admin\WarehouseUnitController (Home).
class WarehouseUnitController extends Controller
{
    use ScopesToMinihouseBuilding;

    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem đơn vị tính.'], 403);
        }

        return response()->json(['data' => WarehouseUnit::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo đơn vị tính.'], 403);
        }

        $data = $request->validate(['name' => 'required|string|max:50']);

        $unit = WarehouseUnit::create($data);

        return response()->json(['data' => $unit], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa đơn vị tính.'], 403);
        }

        $unit = WarehouseUnit::find($id);

        if (! $unit) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate(['name' => 'required|string|max:50']);

        $unit->update($data);

        return response()->json(['data' => $unit]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá đơn vị tính.'], 403);
        }

        $unit = WarehouseUnit::find($id);

        if (! $unit) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $unit->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }
}
