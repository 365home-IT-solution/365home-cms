<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GeneratesUniqueSlug;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;

/**
 * Quản lý CHI NHÁNH (category_type=product, parent_id=null) và KHU VỰC (category_type=product,
 * parent_id=id chi nhánh) — cùng bảng `categories`, cùng logic phân quyền/hành vi với
 * Modules\Category\App\Filament\Resources\CategoryResource (form, scope, cascade partner_id qua
 * App\Observers\CategoryObserver). API này chỉ thao tác category_type='product' — KHÔNG đụng tới
 * category loại 'post' (danh mục bài viết), quản lý ở nơi khác.
 *
 * Field "chi tiết vận hành" (area_sqm, lodging_type, timezone, checkin_time...) KHÔNG thuộc form
 * CategoryResource — chúng được sửa riêng ở trang "Chi tiết cơ sở lưu trú"
 * (app/Filament/Resources/PartnerResource/Pages/BranchDetail.php), nên cũng KHÔNG có trong API này.
 */
class CategoryController extends Controller
{
    use GeneratesUniqueSlug;

    private const IMAGE_RULE = 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120';

    /**
     * GET /api/admin/categories
     * Query params:
     *  - parent_id: bỏ trống = chi nhánh gốc (mặc định); 1 id = khu vực con của chi nhánh đó;
     *               'all' = toàn bộ (chi nhánh + khu vực) dạng phẳng
     *  - search: lọc theo tên
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->visibleCategoriesQuery($user)->withCount('children');

        $parentParam = $request->query('parent_id');
        if ($parentParam === 'all') {
            // không lọc theo parent_id — trả phẳng cả chi nhánh lẫn khu vực
        } elseif ($parentParam !== null && $parentParam !== '') {
            $query->where('parent_id', (int) $parentParam);
        } else {
            $query->whereNull('parent_id');
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $categories = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $categories->map(fn (Category $c) => $this->toItem($c))->values()]);
    }

    // GET /api/admin/categories/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $category = $this->visibleCategoriesQuery($request->user())->withCount('children')->find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy chi nhánh/khu vực.'], 404);
        }

        return response()->json(['data' => $this->toItem($category)]);
    }

    /**
     * POST /api/admin/categories (multipart/form-data nếu có ảnh)
     * - parent_id bỏ trống → tạo CHI NHÁNH. super_admin bắt buộc chọn partner_id; user thường tự
     *   động gán theo partner_id của mình.
     * - parent_id = id 1 chi nhánh có sẵn → tạo KHU VỰC, partner_id LUÔN kế thừa theo chi nhánh cha
     *   (không cho lệch đối tác giữa khu vực và chi nhánh chứa nó).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào, không thể tạo chi nhánh/khu vực.'], 403);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => ['nullable', 'string', 'max:255', Rule::unique('categories', 'slug')],
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|integer|exists:categories,id',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => 'sometimes|in:0,1,true,false',
            'image'       => self::IMAGE_RULE,
            // Chỉ super_admin được set — bỏ qua với user thường (xem bên dưới).
            'partner_id'  => 'nullable|uuid|exists:partners,id',
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Category::find($data['parent_id']);

            if (! $parent || $parent->category_type !== 'product') {
                return response()->json(['message' => 'Chi nhánh cha không hợp lệ.'], 422);
            }

            if (! $user->isSuperAdmin() && $parent->partner_id !== $user->partner_id) {
                return response()->json(['message' => 'Không có quyền tạo khu vực trong chi nhánh này.'], 403);
            }
        }

        $partnerId = $user->isSuperAdmin() ? ($data['partner_id'] ?? null) : $user->partner_id;

        if ($parent) {
            // Khu vực luôn thuộc cùng đối tác với chi nhánh cha — kế thừa, không cho lệch.
            $partnerId = $parent->partner_id;
        } elseif ($user->isSuperAdmin() && empty($partnerId)) {
            return response()->json(['message' => 'Chi nhánh (không có cha) bắt buộc chọn đối tác sở hữu (partner_id).'], 422);
        }

        $category = Category::create([
            'name'          => $data['name'],
            'slug'          => $data['slug'] ?? $this->uniqueSlug(Category::class, $data['name']),
            'description'   => $data['description'] ?? null,
            'parent_id'     => $parent?->id,
            'sort_order'    => $data['sort_order'] ?? 0,
            'category_type' => 'product',
            'status'        => $request->has('status') ? $request->boolean('status') : true,
            'partner_id'    => $partnerId,
        ]);

        if ($request->hasFile('image')) {
            $category->update(['image' => $request->file('image')->store('categories', 'public')]);
        }

        return response()->json(['data' => $this->toItem($category->fresh())], 201);
    }

    // PUT|POST /api/admin/categories/{id} (nhận cả PUT lẫn POST — dùng POST khi cần gửi multipart/form-data kèm ảnh)
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $category = $this->visibleCategoriesQuery($user)->find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy chi nhánh/khu vực.'], 404);
        }

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'slug'        => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
            'description' => 'nullable|string',
            'parent_id'   => 'nullable|integer|exists:categories,id',
            'sort_order'  => 'sometimes|integer|min:0',
            'status'      => 'sometimes|in:0,1,true,false',
            'image'       => self::IMAGE_RULE,
            'partner_id'  => 'nullable|uuid|exists:partners,id',
        ]);

        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            $newParentId = (int) $data['parent_id'];

            if ($newParentId === $category->id) {
                return response()->json(['message' => 'Không thể chọn chính nó làm chi nhánh cha.'], 422);
            }

            $parent = Category::find($newParentId);

            if (! $parent || $parent->category_type !== 'product') {
                return response()->json(['message' => 'Chi nhánh cha không hợp lệ.'], 422);
            }

            if ($this->isDescendantOf($newParentId, $category->id)) {
                return response()->json(['message' => 'Không thể chọn 1 khu vực con của chính nó làm chi nhánh cha (tạo vòng lặp).'], 422);
            }

            if (! $user->isSuperAdmin() && $parent->partner_id !== $user->partner_id) {
                return response()->json(['message' => 'Không có quyền chuyển vào chi nhánh này.'], 403);
            }

            // Khu vực luôn kế thừa đối tác của chi nhánh cha mới — tránh lệch đối tác giữa 2 cấp.
            $data['partner_id'] = $parent->partner_id;
        }

        // partner_id chỉ super_admin được đổi trực tiếp (khi KHÔNG đổi parent_id) — user thường bỏ qua field này dù có gửi lên.
        if (! $user->isSuperAdmin() && ! array_key_exists('parent_id', $data)) {
            unset($data['partner_id']);
        }

        if (! array_key_exists('slug', $data) && isset($data['name']) && $data['name'] !== $category->name) {
            $data['slug'] = $this->uniqueSlug(Category::class, $data['name'], $category->id);
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = $request->boolean('status');
        }

        $category->update($data);

        if ($request->hasFile('image')) {
            $category->update(['image' => $request->file('image')->store('categories', 'public')]);
        }

        return response()->json(['data' => $this->toItem($category->fresh())]);
    }

    /**
     * DELETE /api/admin/categories/{id}
     * Hard delete (Category không dùng Soft Delete) — CHẶN xoá nếu còn khu vực con, đang được gán
     * quyền cho nhân viên (user_branch_permissions), còn phòng hoặc đơn đặt phòng gắn vào, để tránh
     * mồ côi dữ liệu (bảng categories không có FK ràng buộc parent_id/category_id ở nơi khác).
     * Trang Filament hiện tại (EditCategory) xoá đơn lẻ KHÔNG có các chặn này — API cố tình chặt chẽ
     * hơn để không mất dữ liệu lịch sử qua đường API.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = $this->visibleCategoriesQuery($request->user())->find($id);

        if (! $category) {
            return response()->json(['message' => 'Không tìm thấy chi nhánh/khu vực.'], 404);
        }

        if ($category->children()->exists()) {
            return response()->json(['message' => 'Còn khu vực con bên trong — hãy xoá hoặc chuyển khu vực con trước.'], 422);
        }

        if ($category->isAssignedToUserPermission()) {
            return response()->json(['message' => 'Đang được gán quyền cho nhân viên — hãy gỡ quyền trước khi xoá.'], 422);
        }

        if ($category->products()->exists()) {
            return response()->json(['message' => 'Đang có phòng gắn với chi nhánh/khu vực này, không thể xoá.'], 422);
        }

        if ($category->orders()->exists()) {
            return response()->json(['message' => 'Đã có đơn đặt phòng gắn với chi nhánh/khu vực này, không thể xoá.'], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    /**
     * Phạm vi nhìn thấy: super_admin xem hết. User thường chỉ xem category_type='product' thuộc
     * đúng đối tác mình (partner_id) — thu hẹp thêm theo allowedCategoryIds() nếu được cấp quyền
     * chi nhánh cụ thể. Mirror chính xác CategoryResource::getEloquentQuery() (phần product).
     */
    private function visibleCategoriesQuery(User $user): Builder
    {
        $query = Category::query()->where('category_type', 'product');

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if (empty($user->partner_id)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('partner_id', $user->partner_id);

        $allowedIds = $user->allowedCategoryIds();
        if (! empty($allowedIds)) {
            $query->whereIn('id', $allowedIds);
        }

        return $query;
    }

    // true nếu $candidateParentId nằm trong cây con của $ofId — tức gán làm cha sẽ tạo vòng lặp.
    private function isDescendantOf(int $candidateParentId, int $ofId): bool
    {
        $current = Category::find($candidateParentId);

        while ($current && $current->parent_id) {
            if ($current->parent_id === $ofId) {
                return true;
            }
            $current = Category::find($current->parent_id);
        }

        return false;
    }

    private function toItem(Category $c): array
    {
        return [
            'id'             => $c->id,
            'name'           => $c->name,
            'slug'           => $c->slug,
            'description'    => $c->description,
            'parent_id'      => $c->parent_id,
            'sort_order'     => $c->sort_order,
            'status'         => $c->status,
            'image'          => $c->image,
            'image_url'      => $c->image ? asset('storage/' . $c->image) : null,
            'partner_id'     => $c->partner_id,
            'children_count' => $c->children_count ?? $c->children()->count(),
            'created_at'     => $c->created_at,
            'updated_at'     => $c->updated_at,
        ];
    }
}
