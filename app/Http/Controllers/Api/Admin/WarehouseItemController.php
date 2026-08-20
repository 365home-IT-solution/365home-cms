<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Warehouse\App\Models\WarehouseItem;

// Danh mục vật tư (WarehouseItemResource ở Filament). Phạm vi: super_admin thấy & sửa mọi vật tư;
// user thường chỉ thấy/sửa vật tư thuộc đúng đối tác mình (global scope BelongsToPartner không áp
// dụng ngoài Filament panel nên phải lọc thủ công — cùng nguyên tắc ProductController).
//
// Trường "quantity" (tồn kho hiện tại) chỉ nên set tay lúc tạo mới (khởi tạo tồn ban đầu) — sau đó
// PHẢI đi qua WarehouseStockInController/StockOutController/StockCheckController để thay đổi, cho
// đúng lịch sử nhập/xuất/kiểm kê (model events tự cộng/trừ quantity khi tạo dòng chi tiết). update()
// KHÔNG cho sửa quantity trực tiếp để tránh admin vô tình làm lệch tồn kho so với lịch sử phiếu.
class WarehouseItemController extends Controller
{
    /**
     * GET /api/admin/warehouse/items
     * Query params: search (tên/sku), category_id, unit_id, low_stock=1, all=1 (lấy cả vật tư ngừng
     * dùng), per_page (mặc định 20)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseItem::query()->with(['category:id,name', 'unit:id,name']);

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        if (! $request->boolean('all')) {
            $query->where('status', true);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('warehouse_category_id', $request->integer('category_id'));
        }

        if ($request->filled('unit_id')) {
            $query->where('warehouse_unit_id', $request->integer('unit_id'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity', '<=', 'min_quantity')->where('min_quantity', '>', 0);
        }

        $items = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json($items);
    }

    /**
     * GET /api/admin/warehouse/items/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $item = $this->findOwned($request, $id, ['category:id,name', 'unit:id,name']);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        return response()->json(['data' => $item]);
    }

    /**
     * POST /api/admin/warehouse/items
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

        // super_admin PHẢI tự chọn "partner_id" — không thuộc đối tác nào nên không có gì để tự
        // gán. Thiếu bước này thì vật tư lưu với partner_id RỖNG, không đối tác nào thấy được
        // (cùng lỗi vừa xác nhận & sửa ở 3 phiếu nhập/xuất/kiểm kê).
        $data = $request->validate($this->rules(
            requirePartnerId: $user->isSuperAdmin(),
            partnerId: $user->isSuperAdmin() ? null : $user->partner_id,
        ));

        $partnerId = $user->isSuperAdmin() ? $data['partner_id'] : $user->partner_id;

        $item = WarehouseItem::create(array_merge($data, [
            'partner_id' => $partnerId,
            'quantity'   => $data['quantity'] ?? 0,
            'status'     => $data['status'] ?? true,
        ]));

        return response()->json(['data' => $item->load(['category:id,name', 'unit:id,name'])], 201);
    }

    /**
     * PUT /api/admin/warehouse/items/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $item = $this->findOwned($request, $id);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        $data = $request->validate($this->rules(requirePartnerId: false, partnerId: $item->partner_id, isUpdate: true));
        // Xem docblock đầu file — không cho sửa tồn kho trực tiếp qua API này.
        unset($data['quantity']);

        $item->update($data);

        return response()->json(['data' => $item->load(['category:id,name', 'unit:id,name'])]);
    }

    /**
     * DELETE /api/admin/warehouse/items/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = $this->findOwned($request, $id);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        // FK restrictOnDelete từ các dòng phiếu nhập/xuất/kiểm kê — vật tư đã từng phát sinh giao
        // dịch không xoá được (giữ nguyên lịch sử), chỉ nên chuyển "Đang sử dụng" = false thay vào.
        try {
            $item->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Không thể xoá — vật tư đã có lịch sử nhập/xuất/kiểm kê. Hãy tắt "Đang sử dụng" thay vì xoá.',
            ], 409);
        }

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function rules(bool $requirePartnerId, ?string $partnerId, bool $isUpdate = false): array
    {
        $prefix = $isUpdate ? 'sometimes|required|' : 'required|';

        // Chặn 1 đối tác chọn nhầm/cố tình gán nhóm hoặc đơn vị tính của đối tác KHÁC — super_admin
        // không bị giới hạn (được chọn nhóm/đvt của bất kỳ đối tác nào, kể cả dữ liệu dùng chung).
        $scopePartner = fn (Exists $rule) => $partnerId ? $rule->where('partner_id', $partnerId) : $rule;

        return [
            'partner_id'            => [$requirePartnerId ? 'required' : 'sometimes', 'uuid', Rule::exists('partners', 'id')],
            'name'                  => $prefix . 'string|max:255',
            'sku'                   => 'nullable|string|max:100',
            'warehouse_category_id' => ['nullable', 'integer', $scopePartner(Rule::exists('warehouse_categories', 'id'))],
            'warehouse_unit_id'     => [$isUpdate ? 'sometimes' : 'required', 'integer', $scopePartner(Rule::exists('warehouse_units', 'id'))],
            'unit_price'            => 'nullable|numeric|min:0',
            'quantity'              => 'sometimes|numeric|min:0',
            'min_quantity'          => 'sometimes|numeric|min:0',
            'description'           => 'nullable|string',
            'status'                => 'sometimes|in:0,1,true,false',
        ];
    }

    private function findOwned(Request $request, int $id, array $with = []): WarehouseItem|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $item = WarehouseItem::with($with)->find($id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $item->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return $item;
    }
}
