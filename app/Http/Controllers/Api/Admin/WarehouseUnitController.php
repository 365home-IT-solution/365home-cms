<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\App\Models\WarehouseUnit;

// Đơn vị tính (WarehouseUnitResource ở Filament) — xem docblock đầu file WarehouseCategoryController
// (cùng cấu trúc, chỉ khác model quản lý).
class WarehouseUnitController extends Controller
{
    /**
     * GET /api/admin/warehouse/units
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseUnit::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    /**
     * POST /api/admin/warehouse/units
     * "partner_id": BẮT BUỘC nếu gọi bằng tài khoản super_admin (không tự suy ra được đối tác
     * nào); bỏ qua/không cần với tài khoản đối tác thường (luôn lấy theo chính tài khoản đó).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào.'], 403);
        }

        // super_admin PHẢI tự chọn "partner_id" — thiếu bước này thì đơn vị tính lưu với
        // partner_id RỖNG, không đối tác nào thấy được (cùng lỗi vừa xác nhận & sửa ở các API kho
        // khác).
        $data = $request->validate([
            'partner_id' => [$user->isSuperAdmin() ? 'required' : 'sometimes', 'uuid', 'exists:partners,id'],
            'name'       => 'required|string|max:50',
        ]);

        $unit = WarehouseUnit::create([
            'partner_id' => $user->isSuperAdmin() ? $data['partner_id'] : $user->partner_id,
            'name'       => $data['name'],
        ]);

        return response()->json(['data' => $unit], 201);
    }

    /**
     * PUT /api/admin/warehouse/units/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $unit = $this->findOwned($request, $id);
        if (! $unit instanceof WarehouseUnit) {
            return $unit;
        }

        $data = $request->validate([
            'name' => 'required|string|max:50',
        ]);

        $unit->update($data);

        return response()->json(['data' => $unit]);
    }

    /**
     * DELETE /api/admin/warehouse/units/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $unit = $this->findOwned($request, $id);
        if (! $unit instanceof WarehouseUnit) {
            return $unit;
        }

        // warehouse_items.warehouse_unit_id là nullOnDelete() — xoá đơn vị tính không xoá vật tư.
        $unit->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function findOwned(Request $request, int $id): WarehouseUnit|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $unit = WarehouseUnit::find($id);

        if (! $unit) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $unit->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return $unit;
    }
}
