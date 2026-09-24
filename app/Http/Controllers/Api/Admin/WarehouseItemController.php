<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseItem;
use Modules\Warehouse\App\Models\WarehouseStockCheckItem;
use Modules\Warehouse\App\Models\WarehouseStockInItem;
use Modules\Warehouse\App\Models\WarehouseStockMovement;
use Modules\Warehouse\App\Models\WarehouseStockOut;
use Modules\Warehouse\App\Models\WarehouseStockOutItem;
use Modules\Warehouse\App\Models\WarehouseStockReturnItem;

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
     * GET /api/admin/warehouse/scan?code=<mã quét được>&branch_id=<tuỳ chọn>
     * Tra 1 vật tư theo ĐÚNG mã quét được từ camera/máy quét mã vạch (QR hoặc barcode thường —
     * KHÔNG phân biệt loại mã, chỉ so khớp NGUYÊN VĂN chuỗi đọc được với cột "sku" của vật tư,
     * cùng cơ chế với Modules\Warehouse\App\Filament\Support\WarehouseBarcodeScan::handle() đang
     * dùng ở web). CHỈ TRA CỨU, không tự thêm vào phiếu nào — app tự quyết định dùng kết quả này để
     * thêm vào danh sách đang tạo (phiếu nhập/xuất/kiểm kê) rồi gọi POST tương ứng khi hoàn tất.
     *
     * "branch_id": nên truyền khi tài khoản quản lý nhiều chi nhánh và đang thao tác trên phiếu của
     * 1 chi nhánh cụ thể — vật tư trùng SKU ở CHI NHÁNH KHÁC sẽ không khớp. Bỏ trống nếu vật tư
     * không trùng SKU giữa các chi nhánh, hoặc tài khoản chỉ quản lý đúng 1 chi nhánh.
     */
    public function scan(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'code'      => 'required|string|max:255',
            'branch_id' => 'nullable|integer',
        ]);

        $query = WarehouseItem::query()
            ->with(['category:id,name', 'unit:id,name'])
            ->where('sku', $data['code'])
            ->where('status', true);

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);

            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds)) {
                $query->whereIn('branch_id', $branchIds);
            }
        }

        if (! empty($data['branch_id'])) {
            $query->where('branch_id', $data['branch_id']);
        }

        $item = $query->first();

        if (! $item) {
            return response()->json([
                'message' => "Không tìm thấy vật tư với mã: {$data['code']}",
            ], 404);
        }

        return response()->json(['data' => $item]);
    }

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

            // Thu hẹp thêm theo chi nhánh được gán (User::rootProductCategoryIds() —
            // UserBranchPermission) — cùng nguồn xác thực đang dùng ở store()/BelongsToBranch,
            // để API không thấy RỘNG HƠN so với panel Filament (global scope BelongsToBranch chỉ
            // tự áp dụng bên trong Filament, API phải lọc thủ công). Rỗng = không giới hạn thêm.
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds)) {
                $query->whereIn('branch_id', $branchIds);
            }
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
     * Response kèm "qr_code_base64" (PNG mã hoá đúng "sku" — null nếu vật tư chưa có sku) để app
     * hiển thị ngay không cần gọi thêm request — dùng chung hàm sinh QR với PDF in tem
     * (WarehousePrinter::qrPng(), cùng 1 nội dung mã cho cả in giấy lẫn hiển thị trên app).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $item = $this->findOwned($request, $id, ['category:id,name', 'unit:id,name']);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        $item->setAttribute('qr_code_base64', filled($item->sku) ? WarehousePrinter::qrPng($item->sku) : null);

        return response()->json(['data' => $item]);
    }

    /**
     * GET /api/admin/warehouse/items/{id}/qrcode
     * Trả THẲNG file ảnh PNG (Content-Type: image/png) mã QR encode "sku" — dùng khi cần nhúng
     * trực tiếp qua <img src="..."> hoặc gửi cho máy in tem, không cần tự decode base64 phía app.
     */
    public function qrcode(Request $request, int $id)
    {
        $item = $this->findOwned($request, $id);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        if (blank($item->sku)) {
            return response()->json(['message' => 'Vật tư chưa có mã SKU để tạo mã QR.'], 422);
        }

        return response(base64_decode(WarehousePrinter::qrPng($item->sku)), 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * GET /api/admin/warehouse/items/{id}/movements
     * Query params: per_page (mặc định 20)
     * Lịch sử biến động tồn kho của 1 vật tư, gộp từ mọi nguồn (nhập/xuất/hoàn trả/kiểm kê/điều
     * chỉnh thủ công) qua VIEW warehouse_stock_movements (WarehouseStockMovement), mới nhất trước.
     * Trang CUỐI CÙNG (lastPage) có thêm 1 dòng ẢO type="initial" đại diện tồn khởi tạo lúc tạo vật
     * tư (nếu có) — KHÔNG phải 1 phiếu thật nên không tính vào "per_page"/tổng số dòng.
     */
    public function movements(Request $request, int $id): JsonResponse
    {
        $item = $this->findOwned($request, $id);
        if (! $item instanceof WarehouseItem) {
            return $item;
        }

        $movements = WarehouseStockMovement::query()
            ->where('warehouse_item_id', $id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('entry_created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $rows = collect($movements->items());

        // Truy vết chi tiết từng loại phiếu THEO LÔ (gom id trước, fetch 1 lần/loại) — tránh N+1 khi
        // 1 trang có hàng chục dòng lịch sử. "id" của mỗi dòng có dạng "{prefix}-{id dòng chi tiết
        // gốc}" (xem VIEW) — KHÔNG phải id của phiếu (document), chỉ dùng để tra lại đúng dòng gốc.
        $idsByType = $rows->groupBy('type')->map(fn ($group) => $group->map(
            fn (WarehouseStockMovement $m) => (int) Str::afterLast($m->id, '-')
        )->all());

        $outLines = WarehouseStockOutItem::whereIn('id', $idsByType->get('out', []))
            ->with(['stockOut:id,code,product_id', 'stockOut.room:id,name'])
            ->get()->keyBy('id');

        $returnLines = WarehouseStockReturnItem::whereIn('id', $idsByType->get('return', []))
            ->with(['stockReturn:id,code,product_id', 'stockReturn.room:id,name'])
            ->get()->keyBy('id');

        $inLines = WarehouseStockInItem::whereIn('id', $idsByType->get('in', []))
            ->with('stockIn:id,code')
            ->get()->keyBy('id');

        $checkLines = WarehouseStockCheckItem::whereIn('id', $idsByType->get('check', []))
            ->with('stockCheck:id,code')
            ->get()->keyBy('id');

        $userIds = $rows->pluck('created_by')->filter()->unique()->all();
        $users   = User::whereIn('id', $userIds)->get(['id', 'fullname', 'email'])->keyBy('id');

        $typeMap = [
            'in'         => 'stock_in',
            'out'        => 'stock_out',
            'return'     => 'stock_return',
            'check'      => 'stock_check',
            'adjustment' => 'manual_edit',
        ];

        $data = $rows->map(function (WarehouseStockMovement $m) use ($outLines, $returnLines, $inLines, $checkLines, $users, $typeMap) {
            $rawId    = (int) Str::afterLast($m->id, '-');
            $document = null;
            $reason   = null;
            $product  = null;

            if ($m->type === 'out' && ($line = $outLines->get($rawId))) {
                $document = ['id' => $line->stockOut?->id, 'code' => $m->document_code, 'type' => 'stock_out'];
                $reason   = WarehouseStockOut::REASONS[$line->reason] ?? $line->reason;
                $product  = $line->stockOut?->room ? ['id' => $line->stockOut->room->id, 'name' => $line->stockOut->room->name] : null;
            } elseif ($m->type === 'return' && ($line = $returnLines->get($rawId))) {
                $document = ['id' => $line->stockReturn?->id, 'code' => $m->document_code, 'type' => 'stock_return'];
                $product  = $line->stockReturn?->room ? ['id' => $line->stockReturn->room->id, 'name' => $line->stockReturn->room->name] : null;
            } elseif ($m->type === 'in' && ($line = $inLines->get($rawId))) {
                $document = ['id' => $line->stockIn?->id, 'code' => $m->document_code, 'type' => 'stock_in'];
            } elseif ($m->type === 'check' && ($line = $checkLines->get($rawId))) {
                $document = ['id' => $line->stockCheck?->id, 'code' => $m->document_code, 'type' => 'stock_check'];
            }
            // type 'adjustment' (sửa tay): document luôn null — không phát sinh từ 1 phiếu nào.

            $user = $users->get($m->created_by);

            return [
                'id'               => $m->id,
                'type'             => $typeMap[$m->type] ?? $m->type,
                'change'           => (float) $m->quantity_change,
                'quantity_before'  => round((float) $m->balance_after - (float) $m->quantity_change, 2),
                'quantity_after'   => (float) $m->balance_after,
                'document'         => $document,
                'reason'           => $reason,
                'product'          => $product,
                'note'             => $m->note,
                'user'             => $user ? ['id' => $user->id, 'fullname' => $user->fullname ?: Str::before($user->email, '@')] : null,
                'created_at'       => $m->entry_created_at?->toIso8601String(),
            ];
        })->values();

        // Dòng ẢO "initial" — tồn kho khởi tạo lúc tạo vật tư (nhập tay lúc tạo, KHÔNG qua phiếu
        // nhập nào nên không có mặt trong VIEW) — chỉ thêm ở TRANG CUỐI, và chỉ khi dòng CŨ NHẤT
        // trong toàn bộ lịch sử có "quantity_before" > 0 (tức còn tồn chưa giải thích được bởi bất
        // kỳ phiếu nào trước đó).
        if ($movements->currentPage() === $movements->lastPage()) {
            $oldest = $data->last();

            if ($oldest && (float) $oldest['quantity_before'] > 0.0001) {
                $data->push([
                    'id'              => 'initial-' . $item->id,
                    'type'            => 'initial',
                    'change'          => (float) $oldest['quantity_before'],
                    'quantity_before' => 0,
                    'quantity_after'  => (float) $oldest['quantity_before'],
                    'document'        => null,
                    'reason'          => null,
                    'product'         => null,
                    'note'            => 'Tồn khởi tạo lúc tạo vật tư',
                    'user'            => null,
                    'created_at'      => $item->created_at?->toIso8601String(),
                ]);
            } elseif (! $oldest && (float) $item->quantity > 0.0001) {
                // Vật tư CHƯA từng phát sinh phiếu nào — toàn bộ tồn hiện tại là tồn khởi tạo.
                $data->push([
                    'id'              => 'initial-' . $item->id,
                    'type'            => 'initial',
                    'change'          => (float) $item->quantity,
                    'quantity_before' => 0,
                    'quantity_after'  => (float) $item->quantity,
                    'document'        => null,
                    'reason'          => null,
                    'product'         => null,
                    'note'            => 'Tồn khởi tạo lúc tạo vật tư',
                    'user'            => null,
                    'created_at'      => $item->created_at?->toIso8601String(),
                ]);
            }
        }

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page'    => $movements->lastPage(),
                'per_page'     => $movements->perPage(),
                'total'        => $movements->total(),
            ],
        ]);
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
        // "branch_id": tài khoản không phải super_admin dùng chung 1 nguồn xác thực chi nhánh duy
        // nhất — User::rootProductCategoryIds() (chủ đối tác không giới hạn trong PHẠM VI ĐỐI TÁC
        // của họ; nhân viên bị thu hẹp thêm theo UserBranchPermission nếu có gán cụ thể). Chỉ BẮT
        // BUỘC truyền khi tài khoản đó quản lý NHIỀU HƠN 1 chi nhánh — quản lý đúng 1 thì tự gán,
        // không cần truyền.
        $branchIds       = $user->isSuperAdmin() ? [] : $user->rootProductCategoryIds();
        $requireBranchId = $user->isSuperAdmin() || count($branchIds) > 1;

        $data = $request->validate($this->rules(
            requirePartnerId: $user->isSuperAdmin(),
            partnerId: $user->isSuperAdmin() ? null : $user->partner_id,
            requireBranchId: $requireBranchId,
            branchIds: $branchIds,
        ));

        $partnerId = $user->isSuperAdmin() ? $data['partner_id'] : $user->partner_id;
        $branchId  = $user->isSuperAdmin()
            ? $data['branch_id']
            : ($data['branch_id'] ?? ($branchIds[0] ?? null));

        $quantity = $data['quantity'] ?? 0;

        // "quantity_in_use" (phần đang sử dụng) không được vượt tổng tồn kho đang khởi tạo — "Dự
        // phòng" (quantity_reserve) tự tính = quantity - quantity_in_use, không có cột riêng.
        if (isset($data['quantity_in_use']) && $data['quantity_in_use'] > $quantity) {
            throw ValidationException::withMessages([
                'quantity_in_use' => 'Không được lớn hơn Tồn kho hiện tại (quantity).',
            ]);
        }

        $item = WarehouseItem::create(array_merge($data, [
            'partner_id'      => $partnerId,
            'branch_id'       => $branchId,
            'quantity'        => $quantity,
            'quantity_in_use' => $data['quantity_in_use'] ?? 0,
            'status'          => $data['status'] ?? true,
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

        // "quantity_in_use" ĐƯỢC sửa qua API (khác với "quantity" ở trên) — so với tồn kho THỰC TẾ
        // hiện tại của vật tư (item->quantity), không phải giá trị client gửi lên (đã bị loại bỏ).
        if (isset($data['quantity_in_use']) && $data['quantity_in_use'] > $item->quantity) {
            throw ValidationException::withMessages([
                'quantity_in_use' => 'Không được lớn hơn Tồn kho hiện tại (quantity).',
            ]);
        }

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

    private function rules(bool $requirePartnerId, ?string $partnerId, bool $requireBranchId = false, array $branchIds = [], bool $isUpdate = false): array
    {
        $prefix = $isUpdate ? 'sometimes|required|' : 'required|';

        // Chặn 1 đối tác chọn nhầm/cố tình gán nhóm hoặc đơn vị tính của đối tác KHÁC — super_admin
        // không bị giới hạn (được chọn nhóm/đvt của bất kỳ đối tác nào, kể cả dữ liệu dùng chung).
        $scopePartner = fn (Exists $rule) => $partnerId ? $rule->where('partner_id', $partnerId) : $rule;

        // "branch_id": super_admin không giới hạn (chọn bất kỳ chi nhánh nào); tài khoản khác chỉ
        // được chọn trong đúng tập chi nhánh User::rootProductCategoryIds() của họ (đã truyền vào
        // $branchIds từ store()/update()).
        $branchRule = Rule::exists('categories', 'id')->where('category_type', 'product')->whereNull('parent_id');
        if (! empty($branchIds)) {
            $branchRule->whereIn('id', $branchIds);
        }

        return [
            'partner_id'            => [$requirePartnerId ? 'required' : 'sometimes', 'uuid', Rule::exists('partners', 'id')],
            'branch_id'             => [$requireBranchId ? 'required' : 'sometimes', 'integer', $branchRule],
            'name'                  => $prefix . 'string|max:255',
            'sku'                   => 'nullable|string|max:100',
            'warehouse_category_id' => ['nullable', 'integer', $scopePartner(Rule::exists('warehouse_categories', 'id'))],
            'warehouse_unit_id'     => [$isUpdate ? 'sometimes' : 'required', 'integer', $scopePartner(Rule::exists('warehouse_units', 'id'))],
            'unit_price'            => 'nullable|numeric|min:0',
            'quantity'              => 'sometimes|numeric|min:0',
            'quantity_in_use'       => 'sometimes|numeric|min:0',
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

        if (! $user->isSuperAdmin()) {
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds) && ! in_array((int) $item->branch_id, array_map('intval', $branchIds), true)) {
                return response()->json(['message' => 'Không tìm thấy.'], 404);
            }
        }

        return $item;
    }
}
