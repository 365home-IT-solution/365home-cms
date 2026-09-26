<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Minihouse\App\Filament\Support\WarehousePrinter;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Models\WarehouseStockMovement;
use Modules\Minihouse\App\Models\WarehouseStockOutItem;
use Modules\Minihouse\App\Models\WarehouseStockReturnItem;

// Danh mục vật tư — mirror App\Http\Controllers\Api\Admin\WarehouseItemController (Home).
class WarehouseItemController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/warehouse-items/scan?code=&building_id=
    public function scan(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem vật tư.'], 403);
        }

        $code = (string) $request->query('code');
        $permitted = $this->permittedBuildingIds($request);

        $query = WarehouseItem::withoutGlobalScope('activeBuilding')
            ->with(['category', 'unit'])
            ->where('sku', $code)
            ->where('status', true)
            ->whereIn('building_id', $permitted);

        if ($request->filled('building_id')) {
            $query->where('building_id', $request->integer('building_id'));
        }

        $item = $query->first();

        if (! $item) {
            return response()->json(['message' => "Không tìm thấy vật tư với mã: {$code}"], 404);
        }

        return response()->json(['data' => $item]);
    }

    // GET /api/admin/minihouse/warehouse-items?search=&building_id=&category_id=&unit_id=&low_stock=&all=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem vật tư.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $query = WarehouseItem::withoutGlobalScope('activeBuilding')
            ->with(['category', 'unit', 'building:id,name'])
            ->whereIn('building_id', $permitted)
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q2) => $q2
                ->where('name', 'like', '%' . $request->input('search') . '%')
                ->orWhere('sku', 'like', '%' . $request->input('search') . '%')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('warehouse_category_id', $request->integer('category_id')))
            ->when($request->filled('unit_id'), fn ($q) => $q->where('warehouse_unit_id', $request->integer('unit_id')))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('quantity', '<=', 'min_quantity')->where('min_quantity', '>', 0))
            ->when(! $request->boolean('all'), fn ($q) => $q->where('status', true))
            ->orderBy('name');

        return response()->json($query->paginate((int) $request->integer('per_page', 20)));
    }

    // GET /api/admin/minihouse/warehouse-items/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem vật tư.'], 403);
        }

        $item = $this->findInScope($request, $id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $item->load(['category', 'unit', 'building:id,name']);
        $item->qr_code_base64 = filled($item->sku) ? WarehousePrinter::qrPng((string) $item->sku) : null;

        return response()->json(['data' => $item]);
    }

    // GET /api/admin/minihouse/warehouse-items/{id}/qrcode — PNG QR encode đúng SKU (mirror Home).
    public function qrcode(Request $request, int $id)
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem vật tư.'], 403);
        }

        $item = $this->findInScope($request, $id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (blank($item->sku)) {
            return response()->json(['message' => 'Vật tư chưa có SKU để tạo mã QR.'], 422);
        }

        return response(base64_decode(WarehousePrinter::qrPng((string) $item->sku)), 200, ['Content-Type' => 'image/png']);
    }

    // GET /api/admin/minihouse/warehouse-items/{id}/movements?per_page=
    // Trả về "sổ kho" (kardex) của đúng vật tư này, mới nhất trước.
    public function movements(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_warehouse')) {
            return response()->json(['message' => 'Không có quyền xem vật tư.'], 403);
        }

        $item = $this->findInScope($request, $id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $paginator = WarehouseStockMovement::where('warehouse_item_id', $id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('entry_created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        $rows = $paginator->getCollection()->map(fn (WarehouseStockMovement $m) => $this->transformMovement($m))->all();

        // Trang CUỐI: thêm dòng "tồn ban đầu" (type=initial) nếu có số dư tồn có từ trước mọi biến động
        // (vật tư tạo sẵn với quantity > 0) — mirror Home. Số dư đầu = tồn sau biến động CŨ NHẤT trừ
        // đúng biến động đó; chưa có biến động nào mà đang có tồn thì tồn ban đầu = tồn hiện tại.
        if ($paginator->currentPage() >= $paginator->lastPage()) {
            $oldest  = $paginator->getCollection()->last();
            $initial = $oldest ? round((float) $oldest->balance_after - (float) $oldest->quantity_change, 2) : (float) $item->quantity;

            if ($initial > 0) {
                $rows[] = [
                    'id' => 'initial', 'type' => 'initial', 'change' => $initial, 'quantity_after' => $initial,
                    'document' => null, 'reason' => null, 'product' => null, 'note' => 'Tồn ban đầu khi tạo vật tư',
                    'user' => null, 'created_at' => $item->created_at?->toIso8601String(),
                ];
            }
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // POST /api/admin/minihouse/warehouse-items
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_warehouse')) {
            return response()->json(['message' => 'Không có quyền tạo vật tư.'], 403);
        }

        $data = $request->validate($this->rules(create: true));

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo vật tư cho toà nhà này.'], 403);
        }

        if ((float) ($data['quantity_in_use'] ?? 0) > (float) ($data['quantity'] ?? 0)) {
            throw ValidationException::withMessages(['quantity_in_use' => 'Không được vượt quá số lượng tồn.']);
        }

        $item = WarehouseItem::create($data);

        // fresh() — lấy lại các cột có DEFAULT ở DB (quantity/quantity_in_use/min_quantity/status) mà
        // model vừa tạo chưa nạp, nếu không response thiếu các field này.
        return response()->json(['data' => $item->fresh(['category', 'unit'])], 201);
    }

    // PUT/PATCH /api/admin/minihouse/warehouse-items/{id}
    // "quantity" KHÔNG BAO GIỜ sửa được qua đây — chỉ đổi được qua phiếu nhập/xuất/kiểm kê/hoàn trả.
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_warehouse')) {
            return response()->json(['message' => 'Không có quyền sửa vật tư.'], 403);
        }

        $item = $this->findInScope($request, $id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate($this->rules(create: false));
        unset($data['quantity']);

        if (isset($data['building_id']) && ! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền chuyển vật tư sang toà nhà này.'], 403);
        }

        if (array_key_exists('quantity_in_use', $data) && (float) $data['quantity_in_use'] > (float) $item->quantity) {
            throw ValidationException::withMessages(['quantity_in_use' => 'Không được vượt quá số lượng tồn hiện tại.']);
        }

        $item->update($data);

        return response()->json(['data' => $item->load(['category', 'unit'])]);
    }

    // DELETE /api/admin/minihouse/warehouse-items/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_warehouse')) {
            return response()->json(['message' => 'Không có quyền xoá vật tư.'], 403);
        }

        $item = $this->findInScope($request, $id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        try {
            $item->delete();
        } catch (\Illuminate\Database\QueryException) {
            return response()->json(['message' => 'Không thể xoá — vật tư đã có lịch sử nhập/xuất/kiểm kê/hoàn trả. Hãy tắt "Đang sử dụng" thay vì xoá.'], 409);
        }

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function findInScope(Request $request, int $id): ?WarehouseItem
    {
        return WarehouseItem::withoutGlobalScope('activeBuilding')
            ->whereIn('building_id', $this->permittedBuildingIds($request))
            ->find($id);
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes|required';

        return [
            'building_id'           => "{$req}|integer",
            'name'                  => "{$req}|string|max:255",
            // Không kiểm tra trùng — mirror đúng quyết định của Home (SKU có thể trống, không bắt
            // buộc duy nhất, chỉ dùng để quét mã tuỳ chọn).
            'sku'                   => 'nullable|string|max:100',
            'warehouse_category_id' => 'nullable|integer|exists:minihouse_warehouse_categories,id',
            'warehouse_unit_id'     => $create ? 'required|integer|exists:minihouse_warehouse_units,id' : 'sometimes|required|integer|exists:minihouse_warehouse_units,id',
            'unit_price'            => 'nullable|numeric|min:0',
            'quantity'              => 'sometimes|numeric|min:0',
            'quantity_in_use'       => 'sometimes|numeric|min:0',
            'min_quantity'          => 'sometimes|numeric|min:0',
            'description'           => 'nullable|string',
            'status'                => 'sometimes|in:0,1,true,false',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMovement(WarehouseStockMovement $movement): array
    {
        $typeMap = [
            'in'         => 'stock_in',
            'out'        => 'stock_out',
            'return'     => 'stock_return',
            'check'      => 'stock_check',
            'adjustment' => 'manual_edit',
        ];

        [, $sourceId] = explode('-', $movement->id, 2);
        $sourceId = (int) $sourceId;

        $source = match ($movement->type) {
            'in'     => \Modules\Minihouse\App\Models\WarehouseStockInItem::find($sourceId)?->stockIn,
            'out'    => WarehouseStockOutItem::find($sourceId)?->stockOut,
            'return' => WarehouseStockReturnItem::find($sourceId)?->stockReturn,
            'check'  => \Modules\Minihouse\App\Models\WarehouseStockCheckItem::find($sourceId)?->stockCheck,
            default  => null,
        };

        $document = $source ? ['id' => $source->id, 'code' => $source->code, 'type' => $typeMap[$movement->type] ?? $movement->type] : null;

        return [
            'id'               => $movement->id,
            'type'             => $typeMap[$movement->type] ?? $movement->type,
            'change'           => $movement->quantity_change,
            'quantity_after'   => $movement->balance_after,
            'document'         => $document,
            'reason'           => $movement->reason(),
            'product'          => $movement->product(),
            'note'             => $movement->note,
            'user'             => $movement->creator ? ['id' => $movement->creator->id, 'fullname' => $movement->creator->fullname] : null,
            'created_at'       => $movement->occurred_at?->toIso8601String(),
        ];
    }
}
