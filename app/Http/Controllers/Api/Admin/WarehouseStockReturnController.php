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
use Modules\Warehouse\App\Models\WarehouseStockOutItem;
use Modules\Warehouse\App\Models\WarehouseStockReturn;

// Phiếu hoàn trả kho (WarehouseStockReturnResource ở Filament) — trường hợp thực tế: xuất 2 chai
// nước cho phòng, khách chỉ dùng 1, còn 1 chưa dùng thì hoàn lại kho. Tạo/xoá dòng chi tiết LUÔN đi
// qua Eloquent để WarehouseStockReturnItem::created()/updated()/deleted() (model events) tự cộng/
// trừ đúng warehouse_items.quantity, đồng thời tự CHẶN hoàn nhiều hơn số đã thực xuất khi dòng có
// truy vết "warehouse_stock_out_item_id" — xem Modules/Warehouse/App/Models/WarehouseStockReturnItem.php.
//
// Phạm vi: super_admin thấy & sửa mọi phiếu; user thường chỉ thấy/sửa phiếu thuộc đúng đối tác mình.
class WarehouseStockReturnController extends Controller
{
    /**
     * GET /api/admin/warehouse/stock-returns
     * Query params: search (mã phiếu), room_id, per_page
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseStockReturn::query()
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

        if ($request->filled('room_id')) {
            $query->where('product_id', $request->string('room_id'));
        }

        $stockReturns = $query->orderByDesc('returned_at')->paginate($request->integer('per_page', 20));

        return response()->json($stockReturns);
    }

    /**
     * GET /api/admin/warehouse/stock-returns/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $stockReturn = $this->findOwned($request, $id);
        if (! $stockReturn instanceof WarehouseStockReturn) {
            return $stockReturn;
        }

        $stockReturn->load([
            'room', 'employee', 'creator:id,fullname,email',
            'items.item:id,name,warehouse_unit_id', 'items.item.unit:id,name',
            'items.stockOutItem.stockOut:id,code',
        ]);

        return response()->json(['data' => $stockReturn]);
    }

    /**
     * POST /api/admin/warehouse/stock-returns
     * Body: { product_id?, employee_id?, returned_by?, note?,
     *         items: [{ warehouse_stock_out_item_id?, warehouse_item_id?, quantity, note? }] }
     * ("returned_at" KHÔNG nhận từ client — luôn chốt cứng = thời điểm lưu, xem
     * WarehouseStockReturn::creating().)
     * Mỗi dòng PHẢI có "warehouse_stock_out_item_id" (hoàn có truy vết, khuyến nghị — hệ thống tự
     * chặn hoàn vượt số đã xuất) HOẶC "warehouse_item_id" (hoàn không truy vết, không giới hạn).
     * "partner_id": BẮT BUỘC nếu gọi bằng tài khoản super_admin; bỏ qua với tài khoản đối tác thường.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào.'], 403);
        }

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
            $stockReturn = DB::transaction(function () use ($data, $partnerId, $branchId, $user) {
                $stockReturn = WarehouseStockReturn::create([
                    'partner_id'  => $partnerId,
                    'branch_id'   => $branchId,
                    'product_id'  => $data['product_id'] ?? null,
                    'employee_id' => $data['employee_id'] ?? null,
                    'returned_by' => $data['returned_by'] ?? null,
                    'note'        => $data['note'] ?? null,
                    'created_by'  => $user->id,
                ]);

                foreach ($data['items'] as $line) {
                    $stockReturn->items()->create($this->resolveLine($line));
                }

                return $stockReturn;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stockReturn->fresh()->load(['room', 'employee', 'items.item'])], 201);
    }

    /**
     * PUT /api/admin/warehouse/stock-returns/{id}
     * Body giống store(); nếu truyền "items", TOÀN BỘ dòng cũ bị xoá (hoàn tác tồn kho) rồi tạo lại
     * từ danh sách mới — đồng nhất WarehouseStockOutController::update().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $stockReturn = $this->findOwned($request, $id);
        if (! $stockReturn instanceof WarehouseStockReturn) {
            return $stockReturn;
        }

        $data = $request->validate($this->rules(requirePartnerId: false, partnerId: $stockReturn->partner_id, isUpdate: true));

        try {
            DB::transaction(function () use ($stockReturn, $data) {
                $stockReturn->update(collect($data)->only(['product_id', 'employee_id', 'returned_by', 'note'])->toArray());

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

        return response()->json(['data' => $stockReturn->fresh()->load(['room', 'employee', 'items.item'])]);
    }

    /**
     * DELETE /api/admin/warehouse/stock-returns/{id}
     * Xoá phiếu sẽ tự hoàn tác tồn kho đã cộng (xem WarehouseStockReturn::deleting()).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $stockReturn = $this->findOwned($request, $id);
        if (! $stockReturn instanceof WarehouseStockReturn) {
            return $stockReturn;
        }

        $stockReturn->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    // "warehouse_item_id" LUÔN được suy lại từ đúng dòng xuất gốc khi có "warehouse_stock_out_item_id"
    // — GHI ĐÈ giá trị client gửi lên (nếu có), không chỉ điền khi rỗng. Tránh trường hợp client gửi
    // 2 giá trị LỆCH NHAU (VD: warehouse_stock_out_item_id trỏ dòng xuất của vật tư A nhưng
    // warehouse_item_id lại là vật tư B) — nếu không ghi đè, guardAgainstOverReturn() vẫn chỉ kiểm
    // tra theo warehouse_stock_out_item_id nên sẽ CỘNG NHẦM tồn kho cho vật tư B trong khi "mượn"
    // hạn mức còn được hoàn của dòng xuất vật tư A.
    private function resolveLine(array $line): array
    {
        if (! empty($line['warehouse_stock_out_item_id'])) {
            $line['warehouse_item_id'] = WarehouseStockOutItem::whereKey($line['warehouse_stock_out_item_id'])->value('warehouse_item_id');
        }

        return $line;
    }

    private function rules(bool $requirePartnerId, ?string $partnerId, bool $requireBranchId = false, array $branchIds = [], bool $isUpdate = false): array
    {
        $scopePartner = fn (Exists $rule) => $partnerId ? $rule->where('partner_id', $partnerId) : $rule;

        $branchRule = Rule::exists('categories', 'id')->where('category_type', 'product')->whereNull('parent_id');
        if (! empty($branchIds)) {
            $branchRule->whereIn('id', $branchIds);
        }

        return [
            'partner_id'  => [$requirePartnerId ? 'required' : 'sometimes', 'uuid', Rule::exists('partners', 'id')],
            'branch_id'   => [$requireBranchId ? 'required' : 'sometimes', 'integer', $branchRule],
            'product_id'  => 'nullable|uuid|exists:products,id',
            'employee_id' => ['nullable', 'integer', $scopePartner(Rule::exists('employees', 'id'))],
            'returned_by' => 'nullable|string|max:255',
            'note'        => 'nullable|string',
            'items'                                     => ($isUpdate ? 'sometimes|' : '') . 'required|array|min:1',
            'items.*.warehouse_stock_out_item_id'        => ['nullable', 'integer', Rule::exists('warehouse_stock_out_items', 'id')],
            'items.*.warehouse_item_id'                  => [
                'required_without:items.*.warehouse_stock_out_item_id',
                'nullable', 'integer', $scopePartner(Rule::exists('warehouse_items', 'id')),
            ],
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.note'     => 'nullable|string|max:255',
        ];
    }

    private function findOwned(Request $request, int $id): WarehouseStockReturn|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stockReturn = WarehouseStockReturn::find($id);

        if (! $stockReturn) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $stockReturn->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin()) {
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds) && ! in_array((int) $stockReturn->branch_id, array_map('intval', $branchIds), true)) {
                return response()->json(['message' => 'Không tìm thấy.'], 404);
            }
        }

        return $stockReturn;
    }
}
