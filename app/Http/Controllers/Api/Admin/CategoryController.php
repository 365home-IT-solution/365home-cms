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
     * Mỗi item (trừ khi parent_id=all — đã phẳng sẵn) kèm field `children`: TOÀN BỘ khu vực con
     * (mọi cấp, dạng cây lồng nhau, cùng shape đầy đủ với item cha) — để FE không cần gọi thêm 1
     * request/chi nhánh chỉ để lấy danh sách khu vực con của nó.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->visibleCategoriesQuery($user)->withCount('children');

        $parentParam = $request->query('parent_id');
        $flat        = $parentParam === 'all';
        if ($flat) {
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

        $data = $categories->map(fn (Category $c) => $this->toItem($c, withChildren: ! $flat));

        return response()->json(['data' => $data->values()]);
    }

    /**
     * GET /api/admin/categories/tree
     * Toàn bộ chi nhánh (parent_id=null) KÈM khu vực con (mọi cấp), dạng CÂY lồng nhau — dùng cho
     * FE hiển thị dropdown/tree chọn "Thuộc chi nhánh" (chi nhánh cha có thể expand ra khu vực con).
     * Phạm vi hiển thị GIỐNG HỆT field "categories" trả về ở POST /api/admin/login: super_admin thấy
     * hết; chủ đối tác (không có bản ghi branchPermissions riêng) thấy toàn bộ chi nhánh của đối tác
     * mình; nhân viên chỉ thấy chi nhánh được cấp quyền — nhưng đã được cấp branch nào thì thấy
     * TOÀN BỘ khu vực con của branch đó (visibleCategoriesQuery() qua allowedCategoryIds() đã tự
     * gồm cả cây con, không cần lọc thêm ở từng cấp).
     *
     * Query params:
     *  - categories: danh sách slug chi nhánh GỐC cách nhau bởi dấu phẩy (cùng field 'categories'
     *                trả về ở POST /api/admin/login, giống ?categories= của các API admin khác —
     *                dashboard/kpi-stats, rankings, rooms) — chỉ trả về (những) chi nhánh đó kèm
     *                TOÀN BỘ khu vực con của nó, thay vì cả cây. Bỏ trống = trả về mọi chi nhánh
     *                user được xem (mặc định như cũ). Slug không khớp chi nhánh nào user được xem
     *                (gõ nhầm/không có quyền) → trả 'data': [] thay vì bỏ qua filter.
     */
    public function tree(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $categories = $this->visibleCategoriesQuery($user)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'sort_order', 'status', 'image']);

        if ($request->filled('categories')) {
            $slugs = collect(explode(',', (string) $request->string('categories')))
                ->map(fn ($s) => trim($s))
                ->filter()
                ->values();

            $rootIds = $categories->whereNull('parent_id')->whereIn('slug', $slugs)->pluck('id')->all();

            if (empty($rootIds)) {
                return response()->json(['data' => []]);
            }

            $categories = $this->filterToSubtree($categories, $rootIds);
        }

        // groupBy ép key null (chi nhánh gốc) thành '' trên PHP array — dùng sentinel 'root' cho
        // rõ ràng thay vì phụ thuộc hành vi ép kiểu ngầm đó.
        $byParent = $categories->groupBy(fn (Category $c) => $c->parent_id ?? 'root');

        $buildBranch = function ($parentKey) use (&$buildBranch, $byParent) {
            return $byParent->get($parentKey, collect())
                ->map(fn (Category $c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'slug'       => $c->slug,
                    'sort_order' => $c->sort_order,
                    'status'     => $c->status,
                    'image_url'  => $c->image ? asset('storage/' . $c->image) : null,
                    'children'   => $buildBranch($c->id),
                ])
                ->values();
        };

        return response()->json(['data' => $buildBranch('root')]);
    }

    // Thu hẹp $categories (flat, đã lọc theo quyền) chỉ còn lại các id trong $rootIds + toàn bộ
    // hậu duệ của chúng (đệ quy trong bộ nhớ, không query thêm — $categories đã có sẵn cả cây).
    private function filterToSubtree(\Illuminate\Support\Collection $categories, array $rootIds): \Illuminate\Support\Collection
    {
        $keepIds      = $rootIds;
        $currentLevel = $rootIds;

        while (! empty($currentLevel)) {
            $children = $categories->whereIn('parent_id', $currentLevel)->pluck('id')->all();
            if (empty($children)) {
                break;
            }
            $keepIds      = array_merge($keepIds, $children);
            $currentLevel = $children;
        }

        return $categories->whereIn('id', array_unique($keepIds))->values();
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

            if (! $user->isSuperAdmin() && $this->rootPartnerId($parent) !== $user->partner_id) {
                return response()->json(['message' => 'Không có quyền tạo khu vực trong chi nhánh này.'], 403);
            }
        }

        $partnerId = $user->isSuperAdmin() ? ($data['partner_id'] ?? null) : $user->partner_id;

        if ($parent) {
            // Khu vực luôn thuộc cùng đối tác với chi nhánh cha — kế thừa, không cho lệch. Lấy
            // theo CHI NHÁNH GỐC (rootPartnerId), không dùng thẳng $parent->partner_id: nếu $parent
            // lại là 1 khu vực con (parent_id khác null), cột partner_id của chính nó có thể lệch/
            // null với dữ liệu tạo trước khi CategoryObserver cascade tới cấp con (xem CategoryObserver).
            $partnerId = $this->rootPartnerId($parent);
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

            if (! $user->isSuperAdmin() && $this->rootPartnerId($parent) !== $user->partner_id) {
                return response()->json(['message' => 'Không có quyền chuyển vào chi nhánh này.'], 403);
            }

            // Khu vực luôn kế thừa đối tác của CHI NHÁNH GỐC chứa chi nhánh cha mới — tránh lệch
            // đối tác giữa các cấp, và không trực tiếp tin cột partner_id của $parent (xem lý do ở
            // store()/rootPartnerId()).
            $data['partner_id'] = $this->rootPartnerId($parent);
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

    // Đối tác THẬT SỰ của 1 category = partner_id của CHI NHÁNH GỐC (đi ngược parent_id tới khi
    // parent_id null) — không tin thẳng cột partner_id của chính category truyền vào, vì cột đó
    // trên khu vực con có thể lệch/null (dữ liệu tạo trước khi CategoryObserver cascade tới cấp
    // con, xem CategoryObserver::saved()). Dùng khi cần biết 1 chi nhánh/khu vực THUỘC đối tác nào
    // để so quyền (store/update parent_id) — chi nhánh gốc luôn đáng tin vì partner_id được set
    // trực tiếp lúc tạo/sửa chi nhánh, không qua cascade.
    private function rootPartnerId(Category $category): ?string
    {
        $current = $category;
        while ($current->parent_id) {
            $parent = Category::find($current->parent_id);
            if (! $parent) {
                break;
            }
            $current = $parent;
        }

        return $current->partner_id;
    }

    /**
     * Phạm vi nhìn thấy: super_admin xem hết. User thường chỉ xem category_type='product' thuộc
     * đúng đối tác mình — thu hẹp thêm theo allowedCategoryIds() nếu được cấp quyền chi nhánh cụ
     * thể. Mirror ý định của CategoryResource::getEloquentQuery() (phần product), NHƯNG không lọc
     * bằng cột partner_id trên khu vực CON — chỉ chi nhánh GỐC mới dùng cột này (nguồn xác thực
     * đáng tin cậy, luôn được set đúng lúc tạo/sửa chi nhánh). Khu vực con được coi là thuộc đối
     * tác qua QUAN HỆ CHA-CON (đệ quy): cột partner_id của chính khu vực con có thể lệch/null với
     * dữ liệu tạo trước khi CategoryObserver cascade xuống tới cấp con (xem CategoryObserver),
     * nếu lọc trực tiếp bằng cột đó sẽ ẩn mất toàn bộ khu vực con của chi nhánh đúng — đúng lỗi đã
     * gặp ở API tree (chi nhánh cha hiện đúng nhưng children luôn rỗng).
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

        $branchIds = Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->where('partner_id', $user->partner_id)
            ->pluck('id')
            ->toArray();

        // allowedCategoryIds() rỗng = không bị cấp quyền chi nhánh cụ thể (chủ đối tác) → xem hết
        // chi nhánh của đối tác mình, không thu hẹp thêm.
        $allowedIds = $user->allowedCategoryIds();
        if (! empty($allowedIds)) {
            $branchIds = array_values(array_intersect($branchIds, $allowedIds));
        }

        if (empty($branchIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', array_unique(array_merge($branchIds, $this->descendantCategoryIds($branchIds))));
    }

    // Toàn bộ id khu vực con (mọi cấp) của các chi nhánh gốc truyền vào — đệ quy theo parent_id,
    // KHÔNG lọc theo partner_id (xem lý do tại visibleCategoriesQuery()).
    private function descendantCategoryIds(array $rootIds): array
    {
        $descendantIds = [];
        $currentLevel  = $rootIds;

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            if (empty($children)) {
                break;
            }
            $descendantIds = array_merge($descendantIds, $children);
            $currentLevel  = $children;
        }

        return $descendantIds;
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

    private function toItem(Category $c, bool $withChildren = false): array
    {
        $item = [
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

        if ($withChildren) {
            $item['children'] = Category::where('parent_id', $c->id)
                ->where('category_type', 'product')
                ->withCount('children')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $child) => $this->toItem($child, withChildren: true))
                ->values();
        }

        return $item;
    }
}
