<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\Warehouse\App\Models\WarehouseStockIn;

// Phiếu nhập kho (WarehouseStockInResource ở Filament). Tạo/xoá dòng chi tiết LUÔN đi qua Eloquent
// (KHÔNG bulk insert/delete) để WarehouseStockInItem::created()/updated()/deleted() (model events)
// tự cộng/trừ đúng warehouse_items.quantity — xem Modules/Warehouse/App/Models/WarehouseStockInItem.php.
//
// Phạm vi: super_admin thấy & sửa mọi phiếu; user thường chỉ thấy/sửa phiếu thuộc đúng đối tác mình.
class WarehouseStockInController extends Controller
{
    /**
     * GET /api/admin/warehouse/stock-ins
     * Query params: search (mã phiếu), per_page (mặc định 20)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseStockIn::query()->with(['creator:id,fullname,email'])->withCount('items');

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);

            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds)) {
                $query->whereIn('branch_id', $branchIds);
            }
        }

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->string('search') . '%');
        }

        $stockIns = $query->orderByDesc('received_at')->paginate($request->integer('per_page', 20));

        return response()->json($stockIns);
    }

    /**
     * GET /api/admin/warehouse/stock-ins/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $stockIn = $this->findOwned($request, $id);
        if (! $stockIn instanceof WarehouseStockIn) {
            return $stockIn;
        }

        return response()->json(['data' => $stockIn->load(['creator:id,fullname,email', 'items.item:id,name,warehouse_unit_id', 'items.item.unit:id,name'])]);
    }

    /**
     * POST /api/admin/warehouse/stock-ins
     * Body: { note?, items: [{ warehouse_item_id, quantity, unit_price, note? }] }
     * ("received_at" KHÔNG nhận từ client — luôn chốt cứng = thời điểm lưu, xem
     * WarehouseStockIn::creating().)
     * "partner_id": BẮT BUỘC nếu gọi bằng tài khoản super_admin (không tự suy ra được đối tác nào);
     * bỏ qua/không cần với tài khoản đối tác thường (luôn lấy theo chính tài khoản đó).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào.'], 403);
        }

        // super_admin PHẢI tự chọn "partner_id" — không thuộc đối tác nào nên không có gì để tự
        // gán, khác với user thường (lấy thẳng từ tài khoản). Thiếu bước này thì phiếu lưu với
        // partner_id RỖNG, không đối tác nào thấy được (đã xác nhận thực tế qua panel Filament —
        // cùng lý do vừa sửa ở WarehouseStockInForm::partnerInput()) — validate() ngay dưới đây
        // đảm bảo API không lặp lại đúng lỗi đó.
        // "branch_id": tài khoản không phải super_admin dùng chung 1 nguồn xác thực chi nhánh duy
        // nhất — User::rootProductCategoryIds(). Chỉ BẮT BUỘC truyền khi tài khoản đó quản lý
        // NHIỀU HƠN 1 chi nhánh — quản lý đúng 1 thì tự gán, không cần truyền.
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

        $stockIn = DB::transaction(function () use ($data, $partnerId, $branchId, $user) {
            $stockIn = WarehouseStockIn::create([
                'partner_id'             => $partnerId,
                'branch_id'              => $branchId,
                'note'                   => $data['note'] ?? null,
                'created_by'             => $user->id,
            ]);

            foreach ($data['items'] as $line) {
                $stockIn->items()->create($line);
            }

            $stockIn->recalculateTotal();

            return $stockIn;
        });

        return response()->json(['data' => $stockIn->fresh()->load(['items.item'])], 201);
    }

    /**
     * PUT /api/admin/warehouse/stock-ins/{id}
     * Body giống store(); nếu truyền "items", TOÀN BỘ dòng cũ bị xoá và tạo lại từ danh sách mới
     * (đơn giản & luôn đúng — thay vì tự đối chiếu từng dòng đổi/thêm/bớt). Không truyền "items" thì
     * chỉ cập nhật thông tin đầu phiếu, giữ nguyên chi tiết hàng.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $stockIn = $this->findOwned($request, $id);
        if (! $stockIn instanceof WarehouseStockIn) {
            return $stockIn;
        }

        // partner_id KHÔNG cho sửa lại qua update() (không nằm trong whitelist ->only() bên dưới)
        // — không cần bắt buộc lại ở đây.
        $data = $request->validate($this->rules(requirePartnerId: false, partnerId: $stockIn->partner_id, isUpdate: true));

        DB::transaction(function () use ($stockIn, $data) {
            // "received_at" KHÔNG cho sửa lại kể cả khi update phiếu (chốt cứng từ lúc tạo).
            $stockIn->update(collect($data)->only(['note'])->toArray());

            if (array_key_exists('items', $data)) {
                // Xoá qua Eloquent (không phải xoá hàng loạt) để WarehouseStockInItem::deleted()
                // hoàn tác đúng tồn kho đã cộng khi tạo — xem cùng lý do ở
                // WarehouseStockIn::deleting() (Modules/Warehouse/App/Models/WarehouseStockIn.php).
                $stockIn->items->each->delete();

                foreach ($data['items'] as $line) {
                    $stockIn->items()->create($line);
                }
            }

            $stockIn->recalculateTotal();
        });

        return response()->json(['data' => $stockIn->fresh()->load(['items.item'])]);
    }

    /**
     * DELETE /api/admin/warehouse/stock-ins/{id}
     * Xoá phiếu sẽ tự hoàn tác tồn kho đã cộng (xem WarehouseStockIn::deleting()).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $stockIn = $this->findOwned($request, $id);
        if (! $stockIn instanceof WarehouseStockIn) {
            return $stockIn;
        }

        $stockIn->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function rules(bool $requirePartnerId, ?string $partnerId, bool $requireBranchId = false, array $branchIds = [], bool $isUpdate = false): array
    {
        $scopePartner = fn (Exists $rule) => $partnerId ? $rule->where('partner_id', $partnerId) : $rule;

        $branchRule = Rule::exists('categories', 'id')->where('category_type', 'product')->whereNull('parent_id');
        if (! empty($branchIds)) {
            $branchRule->whereIn('id', $branchIds);
        }

        return [
            'partner_id'                    => [$requirePartnerId ? 'required' : 'sometimes', 'uuid', Rule::exists('partners', 'id')],
            'branch_id'                     => [$requireBranchId ? 'required' : 'sometimes', 'integer', $branchRule],
            'note'                          => 'nullable|string',
            'items'                         => ($isUpdate ? 'sometimes|' : '') . 'required|array|min:1',
            'items.*.warehouse_item_id'     => ['required', 'integer', $scopePartner(Rule::exists('warehouse_items', 'id'))],
            'items.*.quantity'              => 'required|numeric|min:0.01',
            'items.*.unit_price'            => 'required|numeric|min:0',
            'items.*.note'                  => 'nullable|string|max:255',
        ];
    }

    private function findOwned(Request $request, int $id): WarehouseStockIn|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stockIn = WarehouseStockIn::find($id);

        if (! $stockIn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $stockIn->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin()) {
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds) && ! in_array((int) $stockIn->branch_id, array_map('intval', $branchIds), true)) {
                return response()->json(['message' => 'Không tìm thấy.'], 404);
            }
        }

        return $stockIn;
    }
}
