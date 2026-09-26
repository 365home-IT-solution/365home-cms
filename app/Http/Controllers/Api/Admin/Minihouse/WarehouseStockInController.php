<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\WarehouseStockIn;

// Phiếu nhập kho — mirror App\Http\Controllers\Api\Admin\WarehouseStockInController (Home).
class WarehouseStockInController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-stock-ins?search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu nhập kho.'], 403);
        }

        $query = WarehouseStockIn::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->with('creator:id,fullname,email')
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->input('search') . '%'))
            ->orderByDesc('received_at');

        return response()->json($query->paginate((int) $request->integer('per_page', 20)));
    }

    // GET /api/admin/minihouse/warehouse-stock-ins/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu nhập kho.'], 403);
        }

        $stockIn = $this->findInScope($request, $id);

        if (! $stockIn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $stockIn->load(['creator', 'items.item.unit'])]);
    }

    // POST /api/admin/minihouse/warehouse-stock-ins
    // Body: { building_id, note?, items: [{warehouse_item_id, quantity, unit_price, note?}] }
    // "received_at" KHÔNG BAO GIỜ nhận từ client — luôn chốt = lúc lưu (xem WarehouseStockIn::booted()).
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo phiếu nhập kho.'], 403);
        }

        $data = $request->validate([
            'building_id'          => 'required|integer',
            'note'                 => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.warehouse_item_id' => 'required|integer|exists:minihouse_warehouse_items,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit_price'   => 'required|numeric|min:0',
            'items.*.note'         => 'nullable|string|max:255',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phiếu cho toà nhà này.'], 403);
        }

        $stockIn = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $stockIn = WarehouseStockIn::create([
                'building_id' => $data['building_id'],
                'note'        => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $line) {
                $stockIn->items()->create($line);
            }

            return $stockIn;
        });

        return response()->json(['data' => $stockIn->fresh(['items.item'])], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-stock-ins/{id}
    // "items" có mặt = XOÁ HẾT dòng cũ (từng dòng, để hoàn tác đúng số lượng) rồi tạo lại từ đầu.
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa phiếu nhập kho.'], 403);
        }

        $stockIn = $this->findInScope($request, $id);

        if (! $stockIn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'note'                 => 'nullable|string',
            'items'                => 'sometimes|array|min:1',
            'items.*.warehouse_item_id' => 'required_with:items|integer|exists:minihouse_warehouse_items,id',
            'items.*.quantity'     => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price'   => 'required_with:items|numeric|min:0',
            'items.*.note'         => 'nullable|string|max:255',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $stockIn) {
            if (array_key_exists('note', $data)) {
                $stockIn->update(['note' => $data['note']]);
            }

            if (array_key_exists('items', $data)) {
                $stockIn->items->each->delete();

                foreach ($data['items'] as $line) {
                    $stockIn->items()->create($line);
                }
            }
        });

        return response()->json(['data' => $stockIn->fresh(['items.item'])]);
    }

    // DELETE /api/admin/minihouse/warehouse-stock-ins/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá phiếu nhập kho.'], 403);
        }

        $stockIn = $this->findInScope($request, $id);

        if (! $stockIn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockIn->delete();

        return response()->json(['message' => 'Đã xoá phiếu nhập kho.']);
    }

    private function findInScope(Request $request, int $id): ?WarehouseStockIn
    {
        return WarehouseStockIn::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->find($id);
    }
}
