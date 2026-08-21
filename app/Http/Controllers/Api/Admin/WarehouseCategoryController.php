<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\App\Models\WarehouseCategory;

// Nhóm vật tư (WarehouseCategoryResource ở Filament) — danh mục lookup đơn giản chỉ có "name",
// dùng cho Select "Nhóm" ở WarehouseItemController::store()/update(). Không dùng
// Concerns\CrudsLookupTable vì bảng này không có slug/description/status như các lookup khác
// (Department, Position...) — chỉ có name + partner_id.
//
// Phạm vi: super_admin thấy & sửa mọi nhóm; user thường chỉ thấy/sửa nhóm thuộc đúng đối tác mình
// — global scope BelongsToPartner KHÔNG áp dụng ngoài Filament panel nên phải lọc thủ công (cùng
// nguyên tắc BranchController/ProductController).
class WarehouseCategoryController extends Controller
{
    /**
     * GET /api/admin/warehouse/categories
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = WarehouseCategory::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    /**
     * POST /api/admin/warehouse/categories
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

        // super_admin PHẢI tự chọn "partner_id" — thiếu bước này thì nhóm lưu với partner_id
        // RỖNG, không đối tác nào thấy được (cùng lỗi vừa xác nhận & sửa ở các API kho khác).
        // Nhóm vật tư DÙNG CHUNG cho mọi chi nhánh của đối tác — không có branch_id.
        $data = $request->validate([
            'partner_id' => [$user->isSuperAdmin() ? 'required' : 'sometimes', 'uuid', 'exists:partners,id'],
            'name'       => 'required|string|max:150',
        ]);

        $category = WarehouseCategory::create([
            'partner_id' => $user->isSuperAdmin() ? $data['partner_id'] : $user->partner_id,
            'name'       => $data['name'],
        ]);

        return response()->json(['data' => $category], 201);
    }

    /**
     * PUT /api/admin/warehouse/categories/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = $this->findOwned($request, $id);
        if (! $category instanceof WarehouseCategory) {
            return $category;
        }

        $data = $request->validate([
            'name' => 'required|string|max:150',
        ]);

        $category->update($data);

        return response()->json(['data' => $category]);
    }

    /**
     * DELETE /api/admin/warehouse/categories/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = $this->findOwned($request, $id);
        if (! $category instanceof WarehouseCategory) {
            return $category;
        }

        // warehouse_items.warehouse_category_id là nullOnDelete() — xoá nhóm không xoá vật tư, chỉ
        // gỡ vật tư khỏi nhóm này (về "Chưa phân nhóm").
        $category->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function findOwned(Request $request, int $id): WarehouseCategory|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $category = WarehouseCategory::find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if (! $user->isSuperAdmin() && $category->partner_id !== $user->partner_id) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return $category;
    }
}
