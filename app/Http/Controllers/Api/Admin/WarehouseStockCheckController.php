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
use Modules\Warehouse\App\Models\WarehouseStockCheck;

// Phiếu kiểm kê kho (WarehouseStockCheckResource ở Filament). Tạo/xoá dòng chi tiết LUÔN đi qua
// Eloquent (KHÔNG bulk insert/delete) để WarehouseStockCheckItem::created()/updated()/deleted()
// (model events) tự điều chỉnh đúng warehouse_items.quantity về khớp tồn thực tế đếm được — xem
// Modules/Warehouse/App/Models/WarehouseStockCheckItem.php.
//
// "Tồn hệ thống" (system_quantity) của mỗi dòng LUÔN tự lấy từ warehouse_items.quantity TẠI THỜI
// ĐIỂM tạo dòng (client không tự truyền số này) — client chỉ cần gửi "Tồn thực tế" (actual_quantity)
// đếm được, "Chênh lệch" tự tính = actual - system.
//
// Phạm vi: super_admin thấy & sửa mọi phiếu; user thường chỉ thấy/sửa phiếu thuộc đúng đối tác mình.
class WarehouseStockCheckController extends Controller
{
    /**
     * GET /api/admin/warehouse/stock-checks
     * Query params: search (mã phiếu), per_page (mặc định 20)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseStockCheck::query()->with(['creator:id,fullname,email'])->withCount('items')
            ->withSum('items', 'difference');

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

        $stockChecks = $query->orderByDesc('checked_at')->paginate($request->integer('per_page', 20));

        return response()->json($stockChecks);
    }

    /**
     * GET /api/admin/warehouse/stock-checks/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $stockCheck = $this->findOwned($request, $id);
        if (! $stockCheck instanceof WarehouseStockCheck) {
            return $stockCheck;
        }

        return response()->json(['data' => $stockCheck->load(['creator:id,fullname,email', 'items.item:id,name,warehouse_unit_id', 'items.item.unit:id,name'])]);
    }

    /**
     * POST /api/admin/warehouse/stock-checks
     * Body: { checked_at, note?, items: [{ warehouse_item_id, actual_quantity, note? }] }
     * ("system_quantity" tự lấy từ tồn kho hiện tại — xem docblock đầu file, KHÔNG nhận từ client)
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
        // WarehouseStockCheckForm::partnerInput()).
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

        $stockCheck = DB::transaction(function () use ($data, $partnerId, $branchId, $user) {
            $stockCheck = WarehouseStockCheck::create([
                'partner_id' => $partnerId,
                'branch_id'  => $branchId,
                'checked_at' => $data['checked_at'],
                'note'       => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($data['items'] as $line) {
                $stockCheck->items()->create([
                    'warehouse_item_id' => $line['warehouse_item_id'],
                    'actual_quantity'   => $line['actual_quantity'],
                    'note'              => $line['note'] ?? null,
                    // system_quantity KHÔNG lấy từ $line — luôn tự tính lại trong
                    // WarehouseStockCheckItem::creating() nếu để trống, đảm bảo đúng tồn TẠI THỜI
                    // ĐIỂM này chứ không phải số client tự gửi lên (có thể đã cũ/sai).
                ]);
            }

            return $stockCheck;
        });

        return response()->json(['data' => $stockCheck->fresh()->load(['items.item'])], 201);
    }

    /**
     * PUT /api/admin/warehouse/stock-checks/{id}
     * Body giống store(); nếu truyền "items", TOÀN BỘ dòng cũ bị xoá (hoàn tác điều chỉnh tồn kho
     * trước đó) rồi tạo lại từ danh sách mới, "system_quantity" tính lại theo tồn TẠI THỜI ĐIỂM sửa.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $stockCheck = $this->findOwned($request, $id);
        if (! $stockCheck instanceof WarehouseStockCheck) {
            return $stockCheck;
        }

        // partner_id KHÔNG cho sửa lại qua update() (không nằm trong whitelist ->only() bên dưới)
        // — không cần bắt buộc lại ở đây.
        $data = $request->validate($this->rules(requirePartnerId: false, partnerId: $stockCheck->partner_id, isUpdate: true));

        DB::transaction(function () use ($stockCheck, $data) {
            $stockCheck->update(collect($data)->only(['checked_at', 'note'])->toArray());

            if (array_key_exists('items', $data)) {
                // Xoá qua Eloquent để WarehouseStockCheckItem::deleted() hoàn tác đúng điều chỉnh
                // tồn kho đã áp dụng khi tạo — cùng lý do ở WarehouseStockCheck::deleting().
                $stockCheck->items->each->delete();

                foreach ($data['items'] as $line) {
                    $stockCheck->items()->create([
                        'warehouse_item_id' => $line['warehouse_item_id'],
                        'actual_quantity'   => $line['actual_quantity'],
                        'note'              => $line['note'] ?? null,
                    ]);
                }
            }
        });

        return response()->json(['data' => $stockCheck->fresh()->load(['items.item'])]);
    }

    /**
     * DELETE /api/admin/warehouse/stock-checks/{id}
     * Xoá phiếu sẽ tự hoàn tác điều chỉnh tồn kho đã áp dụng (xem WarehouseStockCheck::deleting()).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $stockCheck = $this->findOwned($request, $id);
        if (! $stockCheck instanceof WarehouseStockCheck) {
            return $stockCheck;
        }

        $stockCheck->delete();

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
            'checked_at'                    => ($isUpdate ? 'sometimes|' : '') . 'required|date',
            'note'                          => 'nullable|string',
            'items'                         => ($isUpdate ? 'sometimes|' : '') . 'required|array|min:1',
            'items.*.warehouse_item_id'     => ['required', 'integer', $scopePartner(Rule::exists('warehouse_items', 'id'))],
            'items.*.actual_quantity'       => 'required|numeric|min:0',
            'items.*.note'                  => 'nullable|string|max:255',
        ];
    }

    private function findOwned(Request $request, int $id): WarehouseStockCheck|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $stockCheck = WarehouseStockCheck::find($id);

        if (! $stockCheck) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $stockCheck->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin()) {
            $branchIds = $user->rootProductCategoryIds();
            if (! empty($branchIds) && ! in_array((int) $stockCheck->branch_id, array_map('intval', $branchIds), true)) {
                return response()->json(['message' => 'Không tìm thấy.'], 404);
            }
        }

        return $stockCheck;
    }
}
