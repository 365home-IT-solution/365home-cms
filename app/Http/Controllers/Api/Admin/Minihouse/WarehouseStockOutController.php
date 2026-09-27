<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\WarehouseStockOut;

// Phiếu xuất kho — mirror App\Http\Controllers\Api\Admin\WarehouseStockOutController (Home).
class WarehouseStockOutController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-stock-outs?search=&reason=&room_id=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu xuất kho.'], 403);
        }

        $query = WarehouseStockOut::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->with(['room:id,name', 'creator:id,fullname,email'])
            ->withCount('items')
            ->withExists('returnItems')
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->input('search') . '%'))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->input('room_id')))
            ->when($request->filled('reason'), fn ($q) => $q->whereHas('items', fn ($q2) => $q2->where('reason', $request->input('reason'))))
            ->orderByDesc('issued_at');

        $paginator = $query->paginate((int) $request->integer('per_page', 20));

        $paginator->getCollection()->each(function (WarehouseStockOut $stockOut) {
            $stockOut->reasons_summary = $stockOut->reasonsSummary();
            $stockOut->has_returns = (bool) $stockOut->return_items_exists;
        });

        return response()->json($paginator);
    }

    // GET /api/admin/minihouse/warehouse-stock-outs/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu xuất kho.'], 403);
        }

        $stockOut = $this->findInScope($request, $id);

        if (! $stockOut) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockOut->load(['room', 'creator', 'items.item.unit', 'items.returnItems']);

        $totalReturned = 0;

        foreach ($stockOut->items as $line) {
            $line->returned_quantity = $line->returnedQuantity();
            $line->remaining_returnable = $line->remainingReturnable();
            $line->fully_returned = $line->remaining_returnable <= 0.0001;
            $totalReturned += $line->returned_quantity;
        }

        $stockOut->reasons_summary = $stockOut->reasonsSummary();
        $stockOut->has_returns = $totalReturned > 0;
        $stockOut->fully_returned = $stockOut->items->every(fn ($line) => $line->fully_returned);

        return response()->json(['data' => $stockOut]);
    }

    // POST /api/admin/minihouse/warehouse-stock-outs
    // Body: { building_id, room_id?, issued_to?, note?, items: [{warehouse_item_id, reason, quantity, note?}] }
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo phiếu xuất kho.'], 403);
        }

        $data = $request->validate([
            'building_id'                => 'required|integer',
            'room_id'                    => 'nullable|string|exists:products,id',
            'issued_to'                  => 'nullable|string|max:255',
            'note'                       => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.warehouse_item_id'  => 'required|integer|exists:minihouse_warehouse_items,id',
            'items.*.reason'             => ['required', Rule::in(array_keys(WarehouseStockOut::REASONS))],
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.note'               => 'nullable|string|max:255',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phiếu cho toà nhà này.'], 403);
        }

        try {
            $stockOut = DB::transaction(function () use ($data) {
                $stockOut = WarehouseStockOut::create([
                    'building_id' => $data['building_id'],
                    'room_id'     => $data['room_id'] ?? null,
                    'issued_to'   => $data['issued_to'] ?? null,
                    'note'        => $data['note'] ?? null,
                ]);

                foreach ($data['items'] as $line) {
                    $stockOut->items()->create($line);
                }

                return $stockOut;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockOut->fresh(['items.item', 'room'])], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-stock-outs/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa phiếu xuất kho.'], 403);
        }

        $stockOut = $this->findInScope($request, $id);

        if (! $stockOut) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'room_id'                    => 'nullable|string|exists:products,id',
            'issued_to'                  => 'nullable|string|max:255',
            'note'                       => 'nullable|string',
            'items'                      => 'sometimes|array|min:1',
            'items.*.warehouse_item_id'  => 'required_with:items|integer|exists:minihouse_warehouse_items,id',
            'items.*.reason'             => ['required_with:items', Rule::in(array_keys(WarehouseStockOut::REASONS))],
            'items.*.quantity'           => 'required_with:items|numeric|min:0.01',
            'items.*.note'               => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($data, $stockOut) {
                $stockOut->update(collect($data)->except('items')->all());

                if (array_key_exists('items', $data)) {
                    $stockOut->items->each->delete();

                    foreach ($data['items'] as $line) {
                        $stockOut->items()->create($line);
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockOut->fresh(['items.item', 'room'])]);
    }

    // DELETE /api/admin/minihouse/warehouse-stock-outs/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá phiếu xuất kho.'], 403);
        }

        $stockOut = $this->findInScope($request, $id);

        if (! $stockOut) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockOut->delete();

        return response()->json(['message' => 'Đã xoá phiếu xuất kho.']);
    }

    private function findInScope(Request $request, int $id): ?WarehouseStockOut
    {
        return WarehouseStockOut::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->find($id);
    }
}
