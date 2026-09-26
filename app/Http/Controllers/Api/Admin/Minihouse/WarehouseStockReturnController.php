<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\WarehouseStockOutItem;
use Modules\Minihouse\App\Models\WarehouseStockReturn;

// Phiếu hoàn trả kho — mirror App\Http\Controllers\Api\Admin\WarehouseStockReturnController (Home).
class WarehouseStockReturnController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-stock-returns?search=&room_id=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu hoàn trả kho.'], 403);
        }

        $query = WarehouseStockReturn::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->with(['room:id,name', 'creator:id,fullname,email'])
            ->withCount('items')
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->input('search') . '%'))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->input('room_id')))
            ->orderByDesc('returned_at');

        return response()->json($query->paginate((int) $request->integer('per_page', 20)));
    }

    // GET /api/admin/minihouse/warehouse-stock-returns/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem phiếu hoàn trả kho.'], 403);
        }

        $stockReturn = $this->findInScope($request, $id);

        if (! $stockReturn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockReturn->load(['room', 'creator', 'items.item.unit', 'items.stockOutItem.stockOut:id,code', 'items.stockOutItem.returnItems']);

        foreach ($stockReturn->items as $line) {
            if ($line->stockOutItem) {
                $line->remaining_returnable = $line->stockOutItem->remainingReturnable();
            }
        }

        return response()->json(['data' => $stockReturn]);
    }

    // POST /api/admin/minihouse/warehouse-stock-returns
    // Body: { building_id, room_id?, returned_by?, note?, items: [{warehouse_stock_out_item_id?, warehouse_item_id?, quantity, note?}] }
    // Mỗi dòng cần MỘT trong 2: warehouse_stock_out_item_id (hoàn TRUY VẾT, giới hạn số còn lại) HOẶC
    // warehouse_item_id (hoàn KHÔNG TRUY VẾT — vật tư dư tìm thấy, không giới hạn).
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo phiếu hoàn trả kho.'], 403);
        }

        $data = $request->validate([
            'building_id'                        => 'required|integer',
            'room_id'                             => 'nullable|string|exists:products,id',
            'returned_by'                         => 'nullable|string|max:255',
            'note'                                => 'nullable|string',
            'items'                               => 'required|array|min:1',
            'items.*.warehouse_stock_out_item_id' => 'nullable|integer|exists:minihouse_warehouse_stock_out_items,id',
            'items.*.warehouse_item_id'           => 'required_without:items.*.warehouse_stock_out_item_id|nullable|integer|exists:minihouse_warehouse_items,id',
            'items.*.quantity'                    => 'required|numeric|min:0.01',
            'items.*.note'                        => 'nullable|string|max:255',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phiếu cho toà nhà này.'], 403);
        }

        try {
            $stockReturn = DB::transaction(function () use ($data) {
                $stockReturn = WarehouseStockReturn::create([
                    'building_id' => $data['building_id'],
                    'room_id'     => $data['room_id'] ?? null,
                    'returned_by' => $data['returned_by'] ?? null,
                    'note'        => $data['note'] ?? null,
                ]);

                foreach ($data['items'] as $line) {
                    $stockReturn->items()->create($this->resolveLine($line));
                }

                return $stockReturn;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockReturn->fresh(['items.item', 'room'])], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-stock-returns/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa phiếu hoàn trả kho.'], 403);
        }

        $stockReturn = $this->findInScope($request, $id);

        if (! $stockReturn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'room_id'                             => 'nullable|string|exists:products,id',
            'returned_by'                         => 'nullable|string|max:255',
            'note'                                => 'nullable|string',
            'items'                               => 'sometimes|array|min:1',
            'items.*.warehouse_stock_out_item_id' => 'nullable|integer|exists:minihouse_warehouse_stock_out_items,id',
            'items.*.warehouse_item_id'           => 'nullable|integer|exists:minihouse_warehouse_items,id',
            'items.*.quantity'                    => 'required_with:items|numeric|min:0.01',
            'items.*.note'                        => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($data, $stockReturn) {
                $stockReturn->update(collect($data)->except('items')->all());

                if (array_key_exists('items', $data)) {
                    $stockReturn->items->each->delete();

                    foreach ($data['items'] as $line) {
                        $stockReturn->items()->create($this->resolveLine($line));
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockReturn->fresh(['items.item', 'room'])]);
    }

    // DELETE /api/admin/minihouse/warehouse-stock-returns/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá phiếu hoàn trả kho.'], 403);
        }

        $stockReturn = $this->findInScope($request, $id);

        if (! $stockReturn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $stockReturn->delete();

        return response()->json(['message' => 'Đã xoá phiếu hoàn trả kho.']);
    }

    // Nếu có warehouse_stock_out_item_id — TỰ SUY warehouse_item_id từ đúng dòng xuất đó, KHÔNG tin
    // warehouse_item_id client tự gửi kèm (tránh gửi lệch ID để hưởng "hạn mức còn lại" của 1 vật tư
    // khác trong khi vẫn cộng nhầm tồn cho vật tư client muốn).
    private function resolveLine(array $line): array
    {
        if (filled($line['warehouse_stock_out_item_id'] ?? null)) {
            $stockOutItem = WarehouseStockOutItem::find($line['warehouse_stock_out_item_id']);
            $line['warehouse_item_id'] = $stockOutItem?->warehouse_item_id;
        }

        return $line;
    }

    private function findInScope(Request $request, int $id): ?WarehouseStockReturn
    {
        return WarehouseStockReturn::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->find($id);
    }
}
