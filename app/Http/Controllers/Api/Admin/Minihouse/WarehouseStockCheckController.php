<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\WarehouseStockCheck;

// Phiếu kiểm kê — mirror App\Http\Controllers\Api\Admin\WarehouseStockCheckController (Home).
class WarehouseStockCheckController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-stock-checks?search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu kiểm kê.'], 403);
        }

        $query = WarehouseStockCheck::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->with('creator:id,fullname,email')
            ->withCount('items')
            ->withSum('items', 'difference')
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->input('search') . '%'))
            ->orderByDesc('checked_at');

        return response()->json($query->paginate((int) $request->integer('per_page', 20)));
    }

    // GET /api/admin/minihouse/warehouse-stock-checks/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu kiểm kê.'], 403);
        }

        $stockCheck = $this->findInScope($request, $id);

        if (! $stockCheck) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $stockCheck->load(['creator', 'items.item.unit'])]);
    }

    // POST /api/admin/minihouse/warehouse-stock-checks
    // Body: { building_id, checked_at, note?, items: [{warehouse_item_id, actual_quantity, note?}] }
    // "system_quantity" KHÔNG BAO GIỜ nhận từ client — luôn tự đọc tồn SỐNG lúc lưu (xem
    // WarehouseStockCheckItem::saving()). Khác StockIn/StockOut: "checked_at" LÀ client-supplied.
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo phiếu kiểm kê.'], 403);
        }

        $data = $request->validate([
            'building_id'                => 'required|integer',
            'checked_at'                 => 'required|date',
            'note'                       => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.warehouse_item_id'  => 'required|integer|exists:minihouse_warehouse_items,id',
            'items.*.actual_quantity'    => 'required|numeric|min:0',
            'items.*.note'               => 'nullable|string|max:255',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phiếu cho toà nhà này.'], 403);
        }

        $stockCheck = DB::transaction(function () use ($data) {
            $stockCheck = WarehouseStockCheck::create([
                'building_id' => $data['building_id'],
                'checked_at'  => $data['checked_at'],
                'note'        => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $stockCheck->items()->create($line);
            }

            return $stockCheck;
        });

        return response()->json(['data' => $stockCheck->fresh(['items.item'])], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-stock-checks/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa phiếu kiểm kê.'], 403);
        }

        $stockCheck = $this->findInScope($request, $id);

        if (! $stockCheck) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'checked_at'                 => 'sometimes|required|date',
            'note'                       => 'nullable|string',
            'items'                      => 'sometimes|array|min:1',
            'items.*.warehouse_item_id'  => 'required_with:items|integer|exists:minihouse_warehouse_items,id',
            'items.*.actual_quantity'    => 'required_with:items|numeric|min:0',
            'items.*.note'               => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data, $stockCheck) {
            $stockCheck->update(collect($data)->only(['checked_at', 'note'])->all());

            if (array_key_exists('items', $data)) {
                $stockCheck->items->each->delete();

                foreach ($data['items'] as $line) {
                    $stockCheck->items()->create($line);
                }
            }
        });

        return response()->json(['data' => $stockCheck->fresh(['items.item'])]);
    }

    // DELETE /api/admin/minihouse/warehouse-stock-checks/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá phiếu kiểm kê.'], 403);
        }

        $stockCheck = $this->findInScope($request, $id);

        if (! $stockCheck) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockCheck->delete();

        return response()->json(['message' => 'Đã xoá phiếu kiểm kê.']);
    }

    private function findInScope(Request $request, int $id): ?WarehouseStockCheck
    {
        return WarehouseStockCheck::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->find($id);
    }
}
