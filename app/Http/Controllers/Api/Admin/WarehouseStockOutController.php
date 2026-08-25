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
use Modules\Warehouse\App\Models\WarehouseStockOut;

// Phiếu xuất kho (WarehouseStockOutResource ở Filament). Tạo/xoá dòng chi tiết LUÔN đi qua Eloquent
// (KHÔNG bulk insert/delete) để WarehouseStockOutItem::created()/updated()/deleted() (model events)
// tự trừ/hoàn tác đúng warehouse_items.quantity, đồng thời tự CHẶN xuất vượt tồn kho khả dụng — xem
// Modules/Warehouse/App/Models/WarehouseStockOutItem.php.
//
// "Lý do xuất" thuộc về TỪNG DÒNG (items.*.reason, WarehouseStockOut::REASONS), không phải cả
// phiếu — 1 phiếu có thể gồm nhiều lý do khác nhau (vừa hao hụt vừa housekeeping bình thường trong
// cùng 1 lượt dọn phòng).
//
// Phạm vi: super_admin thấy & sửa mọi phiếu; user thường chỉ thấy/sửa phiếu thuộc đúng đối tác mình.
class WarehouseStockOutController extends Controller
{
    /**
     * GET /api/admin/warehouse/stock-outs
     * Query params: search (mã phiếu), reason (lọc phiếu có ít nhất 1 dòng khớp lý do), room_id, per_page
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseStockOut::query()
            ->with(['room:id,name', 'employee:id,name', 'creator:id,fullname,email'])
            ->withCount('items');

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

        if ($request->filled('reason')) {
            $query->whereHas('items', fn ($q) => $q->where('reason', $request->string('reason')));
        }

        if ($request->filled('room_id')) {
            $query->where('product_id', $request->string('room_id'));
        }

        $stockOuts = $query->orderByDesc('issued_at')->paginate($request->integer('per_page', 20));

        $stockOuts->getCollection()->transform(function (WarehouseStockOut $stockOut) {
            $stockOut->setAttribute('reasons_summary', $stockOut->reasonsSummary());

            return $stockOut;
        });

        return response()->json($stockOuts);
    }

    /**
     * GET /api/admin/warehouse/stock-outs/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $stockOut = $this->findOwned($request, $id);
        if (! $stockOut instanceof WarehouseStockOut) {
            return $stockOut;
        }

        $stockOut->load(['room', 'employee', 'creator:id,fullname,email', 'items.item:id,name,warehouse_unit_id', 'items.item.unit:id,name']);
        $stockOut->setAttribute('reasons_summary', $stockOut->reasonsSummary());

        return response()->json(['data' => $stockOut]);
    }

    /**
     * POST /api/admin/warehouse/stock-outs
     * Body: { product_id?, employee_id?, issued_to?, note?,
     *         items: [{ warehouse_item_id, reason, quantity, note? }] }
     * ("issued_at" KHÔNG nhận từ client — luôn chốt cứng = thời điểm lưu, xem
     * WarehouseStockOut::creating().)
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
        // gán. Thiếu bước này thì phiếu lưu với partner_id RỖNG, không đối tác nào thấy được (đã
        // xác nhận thực tế qua panel Filament — cùng lý do vừa sửa ở
        // WarehouseStockOutForm::partnerInput()).
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

        try {
            $stockOut = DB::transaction(function () use ($data, $partnerId, $branchId, $user) {
                $stockOut = WarehouseStockOut::create([
                    'partner_id'  => $partnerId,
                    'branch_id'   => $branchId,
                    'product_id'  => $data['product_id'] ?? null,
                    'employee_id' => $data['employee_id'] ?? null,
                    'issued_to'   => $data['issued_to'] ?? null,
                    'note'        => $data['note'] ?? null,
                    'created_by'  => $user->id,
                ]);

                foreach ($data['items'] as $line) {
                    $stockOut->items()->create($line);
                }

                return $stockOut;
            });
        } catch (\RuntimeException $e) {
            // Vượt tồn kho khả dụng — xem WarehouseStockOutItem::creating()/updating().
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockOut->fresh()->load(['room', 'employee', 'items.item'])], 201);
    }

    /**
     * PUT /api/admin/warehouse/stock-outs/{id}
     * Body giống store(); nếu truyền "items", TOÀN BỘ dòng cũ bị xoá (hoàn tác tồn kho) rồi tạo lại
     * từ danh sách mới — đơn giản & luôn đúng thay vì tự đối chiếu từng dòng đổi/thêm/bớt.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $stockOut = $this->findOwned($request, $id);
        if (! $stockOut instanceof WarehouseStockOut) {
            return $stockOut;
        }

        // partner_id KHÔNG cho sửa lại qua update() (không nằm trong whitelist ->only() bên dưới)
        // — không cần bắt buộc lại ở đây.
        $data = $request->validate($this->rules(requirePartnerId: false, partnerId: $stockOut->partner_id, isUpdate: true));

        try {
            DB::transaction(function () use ($stockOut, $data) {
                // "issued_at" KHÔNG cho sửa lại kể cả khi update phiếu (chốt cứng từ lúc tạo).
                $stockOut->update(collect($data)->only(['product_id', 'employee_id', 'issued_to', 'note'])->toArray());

                if (array_key_exists('items', $data)) {
                    // Xoá qua Eloquent để WarehouseStockOutItem::deleted() hoàn tác đúng tồn kho đã
                    // trừ khi tạo — cùng lý do ở WarehouseStockOut::deleting().
                    $stockOut->items->each->delete();

                    foreach ($data['items'] as $line) {
                        $stockOut->items()->create($line);
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockOut->fresh()->load(['room', 'employee', 'items.item'])]);
    }

    /**
     * DELETE /api/admin/warehouse/stock-outs/{id}
     * Xoá phiếu sẽ tự hoàn tác tồn kho đã trừ (xem WarehouseStockOut::deleting()).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $stockOut = $this->findOwned($request, $id);
        if (! $stockOut instanceof WarehouseStockOut) {
            return $stockOut;
        }

        $stockOut->delete();

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
            'partner_id'             => [$requirePartnerId ? 'required' : 'sometimes', 'uuid', Rule::exists('partners', 'id')],
            'branch_id'              => [$requireBranchId ? 'required' : 'sometimes', 'integer', $branchRule],
            'product_id'             => 'nullable|uuid|exists:products,id',
            'employee_id'            => ['nullable', 'integer', $scopePartner(Rule::exists('employees', 'id'))],
            'issued_to'              => 'nullable|string|max:255',
            'note'                   => 'nullable|string',
            'items'                  => ($isUpdate ? 'sometimes|' : '') . 'required|array|min:1',
            'items.*.warehouse_item_id' => ['required', 'integer', $scopePartner(Rule::exists('warehouse_items', 'id'))],
            'items.*.reason'         => ['required', Rule::in(array_keys(WarehouseStockOut::REASONS))],
            'items.*.quantity'       => 'required|numeric|min:0.01',
            'items.*.note'           => 'nullable|string|max:255',
        ];
    }

    private function findOwned(Request $request, int $id): WarehouseStockOut|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stockOut = WarehouseStockOut::find($id);

        if (! $stockOut) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $stockOut->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin()) {
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds) && ! in_array((int) $stockOut->branch_id, array_map('intval', $branchIds), true)) {
                return response()->json(['message' => 'Không tìm thấy.'], 404);
            }
        }

        return $stockOut;
    }
}
