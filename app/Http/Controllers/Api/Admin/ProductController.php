<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GeneratesUniqueSlug;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Spatie\Tags\Tag;

/**
 * Quản lý PHÒNG (bảng `products`, module Product) — CRUD đầy đủ cho màn "Quản lý phòng" của admin.
 * Khác với App\Http\Controllers\Api\Admin\RoomController (chỉ phục vụ tra cứu lịch/giá đặt phòng
 * cho luồng tạo đơn) — controller này quản lý CHÍNH bản ghi phòng: thông tin, chi nhánh áp dụng,
 * tiện ích, dịch vụ bổ sung, ảnh...
 *
 * Field trong request khớp với Modules\Product\App\Filament\Resources\ProductResource\Forms\ProductForm:
 *  - categories (chi nhánh/khu vực áp dụng), tags (tiện ích — Spatie Tag, KHÔNG phải RoomAmenity),
 *    additional_services (dịch vụ bổ sung), name/slug/wifi/home_code/address/latitude/longitude/
 *    hotline/styles, short_description/description, setting_video_room{url,ratio,title},
 *    image_main (collection "Ảnh bìa"), gallery[] (collection "Thư viện").
 *
 * Phạm vi hiển thị/sửa: super_admin xem & sửa mọi phòng; user thường chỉ xem/sửa phòng thuộc đúng
 * đối tác mình (products.partner_id) — global scope BelongsToPartner KHÔNG áp dụng ngoài Filament
 * panel nên phải lọc thủ công (cùng nguyên tắc RoomController/CategoryController/ServiceController).
 */
class ProductController extends Controller
{
    use GeneratesUniqueSlug;

    // Không dùng rule 'image' — Laravel hardcode whitelist mime của rule đó (jpg,jpeg,png,gif,bmp,
    // svg,webp), KHÔNG có avif, nên avif hợp lệ vẫn bị chặn dù có mặt trong 'mimes' bên dưới. Chỉ
    // cần 'mimes' (đã tự kiểm tra đúng là file ảnh qua mime type thật, không chỉ đọc phần đuôi file).
    private const IMAGE_RULE = 'file|mimes:jpg,jpeg,png,webp,avif|max:3072';

    /**
     * GET /api/admin/products
     * Danh sách phòng — ảnh chính, tên, chi nhánh (categories), trạng thái.
     * Query params:
     *  - category_id : lọc theo 1 chi nhánh/khu vực (gồm cả khu vực con nếu truyền chi nhánh gốc)
     *  - search      : lọc theo tên phòng HOẶC tên chi nhánh
     *  - status      : lọc theo trạng thái hoạt động (is_activated) — 1/0/true/false
     *  - per_page    : mặc định 20
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Product::query()->with(['categories:id,name,slug,parent_id']);

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        if ($request->filled('category_id')) {
            $categoryIds = $this->expandCategoryIds((int) $request->integer('category_id'));
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('categories', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->has('status')) {
            $query->where('is_activated', $request->boolean('status'));
        }

        $products = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        $products->getCollection()->transform(fn (Product $p) => $this->toListItem($p));

        return response()->json($products);
    }

    /**
     * GET /api/admin/products/{id}
     * Chi tiết phòng đầy đủ: thông tin cơ bản, video, ảnh chính/phụ, chi nhánh áp dụng, tiện ích,
     * dịch vụ bổ sung.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())
            ->with(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image'])
            ->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($product)]);
    }

    /**
     * POST /api/admin/products (multipart/form-data — bắt buộc có ảnh chính)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào, không thể tạo phòng.'], 403);
        }

        $data = $request->validate($this->rules());

        $categoryError = $this->validateCategoryIds($user, $data['categories']);
        if ($categoryError) {
            return response()->json(['message' => $categoryError], 422);
        }

        $partnerId = $user->isSuperAdmin() ? ($data['partner_id'] ?? null) : $user->partner_id;
        if ($user->isSuperAdmin() && empty($partnerId)) {
            return response()->json(['message' => 'Bắt buộc chọn đối tác sở hữu (partner_id) khi tạo phòng.'], 422);
        }

        if (! empty($data['additional_services'])) {
            $serviceError = $this->validateServiceIds($partnerId, $data['additional_services']);
            if ($serviceError) {
                return response()->json(['message' => $serviceError], 422);
            }
        }

        $product = DB::transaction(function () use ($data, $partnerId) {
            $product = Product::create([
                'partner_id'         => $partnerId,
                'name'                => $data['name'],
                'slug'                => $data['slug'] ?? $this->uniqueSlug(Product::class, $data['name']),
                'wifi'                => $data['wifi'] ?? null,
                'home_code'           => $data['home_code'] ?? null,
                'address'             => $data['address'] ?? null,
                'latitude'            => $data['latitude'] ?? null,
                'longitude'           => $data['longitude'] ?? null,
                'hotline'             => $data['hotline'] ?? null,
                'styles'              => $data['styles'],
                'short_description'   => $data['short_description'] ?? null,
                'description'         => $data['description'] ?? null,
                'setting_video_room'  => $data['setting_video_room'] ?? null,
            ]);

            $product->categories()->sync($data['categories']);
            $product->tags()->sync($data['tags'] ?? []);
            $product->additionalServices()->sync($data['additional_services'] ?? []);

            return $product;
        });

        $this->handleImages($request, $product);

        // Lấy lại object hoàn toàn mới trước khi build response — phòng trường hợp Spatie MediaLibrary
        // cache stale relation 'media' trên $product (đã từng xảy ra khi handleImages() lỡ gọi
        // getMedia() giữa chừng, khiến toDetailItem() đọc phải snapshot media CŨ dù DB đã có ảnh mới —
        // xem lịch sử fix). fresh() đảm bảo mọi getMedia()/getFirstMediaUrl() bên dưới luôn đọc thẳng DB.
        $product = $product->fresh(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image']);

        return response()->json(['data' => $this->toDetailItem($product)], 201);
    }

    /**
     * PUT|POST /api/admin/products/{id} (nhận cả PUT lẫn POST — dùng POST khi cần gửi kèm ảnh,
     * PHP không tự parse multipart cho method PUT thật).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $product = $this->visibleProductsQuery($user)->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate($this->rules(isUpdate: true, productId: $product->id));

        if (array_key_exists('categories', $data)) {
            $categoryError = $this->validateCategoryIds($user, $data['categories']);
            if ($categoryError) {
                return response()->json(['message' => $categoryError], 422);
            }
        }

        if (array_key_exists('additional_services', $data)) {
            $serviceError = $this->validateServiceIds($product->partner_id, $data['additional_services']);
            if ($serviceError) {
                return response()->json(['message' => $serviceError], 422);
            }
        }

        // Khác với store() (luôn bắt đầu từ 0 ảnh nên rule 'gallery' => 'max:8' đã đủ chặn), update()
        // phải cộng dồn ảnh gallery ĐÃ CÓ SẴN của phòng — kiểm tra TRƯỚC khi ghi bất kỳ thay đổi nào
        // xuống DB (fail fast), không để handleImages() âm thầm bỏ qua ảnh mới nếu vượt hạn mức.
        if (array_key_exists('gallery', $data)) {
            $existingGalleryCount = $product->getMedia('Thư viện')->count();
            $newGalleryCount      = count($data['gallery']);

            if ($existingGalleryCount + $newGalleryCount > 8) {
                return response()->json([
                    'message' => "Tổng số ảnh phụ vượt quá 8 (hiện có {$existingGalleryCount} ảnh, thêm mới {$newGalleryCount} ảnh). Hãy xoá bớt ảnh cũ hoặc thêm ít ảnh mới hơn.",
                ], 422);
            }
        }

        if (! array_key_exists('slug', $data) && isset($data['name']) && $data['name'] !== $product->name) {
            $data['slug'] = $this->uniqueSlug(Product::class, $data['name'], $product->id);
        }

        DB::transaction(function () use ($product, $data) {
            $product->update(collect($data)->only([
                'name', 'slug', 'wifi', 'home_code', 'address', 'latitude', 'longitude', 'hotline',
                'styles', 'short_description', 'description', 'setting_video_room',
            ])->toArray());

            if (array_key_exists('categories', $data)) {
                $product->categories()->sync($data['categories']);
            }
            if (array_key_exists('tags', $data)) {
                $product->tags()->sync($data['tags']);
            }
            if (array_key_exists('additional_services', $data)) {
                $product->additionalServices()->sync($data['additional_services']);
            }
        });

        $this->handleImages($request, $product);

        $product->load(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image']);

        return response()->json(['data' => $this->toDetailItem($product->fresh([
            'categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image',
        ]))]);
    }

    /**
     * DELETE /api/admin/products/{id}
     * Chặn xoá nếu phòng còn đơn đặt phòng gắn vào (order_items) — tránh mất dữ liệu lịch sử.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ($product->orderItems()->exists()) {
            return response()->json(['message' => 'Phòng đang có đơn đặt phòng gắn vào, không thể xoá.'], 422);
        }

        $product->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    /**
     * POST /api/admin/products/discount-settings
     * Thiết lập điều kiện giảm giá áp dụng cho 1 hoặc nhiều phòng cùng lúc — ghi đè
     * full_booking_discount / bulk_discount_rules / room_config trên bảng products.
     * Chỉ field nào THỰC SỰ có mặt trong request mới được ghi đè (field vắng mặt giữ nguyên giá trị
     * cũ của từng phòng — tránh xoá nhầm 1 field chưa muốn đổi khi chỉ định sửa field còn lại).
     *
     * Body:
     *  - room_ids (required)             : mảng id phòng cần áp dụng
     *  - full_booking_discount           : chuỗi mô tả giảm giá khi đặt trọn (vd "10%")
     *  - bulk_discount_rules             : mảng quy tắc giảm giá theo số lượng/khung giờ, vd
     *                                       [{"min_slots":3,"discount_percent":10}, ...] — cấu trúc
     *                                       do FE tự định nghĩa, BE chỉ lưu nguyên mảng JSON.
     *  - room_config                     : object cấu hình phòng, vd
     *                                       {"max_free_guests":2,"extra_guest_fee":50000}
     * Phòng ngoài phạm vi đối tác (user thường) bị bỏ qua, trả về trong 'skipped' thay vì lỗi cả request.
     */
    public function discountSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'room_ids'                          => 'required|array|min:1',
            'room_ids.*'                         => 'string',
            'full_booking_discount'             => 'nullable|string|max:50',
            'bulk_discount_rules'                => 'nullable|array',
            'room_config'                         => 'nullable|array',
            'room_config.max_free_guests'        => 'nullable|integer|min:0',
            'room_config.extra_guest_fee'        => 'nullable|numeric|min:0',
        ]);

        if (! array_key_exists('full_booking_discount', $data)
            && ! array_key_exists('bulk_discount_rules', $data)
            && ! array_key_exists('room_config', $data)) {
            return response()->json(['message' => 'Cần nhập ít nhất 1 trong 3: full_booking_discount, bulk_discount_rules, room_config.'], 422);
        }

        $update = collect($data)->only(['full_booking_discount', 'bulk_discount_rules', 'room_config'])->toArray();

        $query = Product::query()->whereIn('id', $data['room_ids']);
        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        $foundIds = $query->pluck('id')->all();
        $skipped  = array_values(array_diff($data['room_ids'], $foundIds));

        if (! empty($foundIds)) {
            Product::whereIn('id', $foundIds)->update($update);
        }

        return response()->json([
            'message' => 'Đã áp dụng.',
            'updated' => $foundIds,
            'skipped' => $skipped,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rules(bool $isUpdate = false, ?string $productId = null): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'name'                => "{$required}|string|max:255",
            'slug'                => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($productId)],
            'wifi'                => 'nullable|string|max:100',
            'home_code'           => 'nullable|string|max:255',
            'address'             => 'nullable|string|max:100',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'hotline'             => 'nullable|string|max:255',
            'styles'              => "{$required}|integer|in:1,2",
            'short_description'   => 'nullable|string',
            'description'         => 'nullable|string',

            'setting_video_room'         => 'nullable|array',
            'setting_video_room.url'     => 'nullable|string|max:1000',
            'setting_video_room.ratio'   => 'nullable|in:16:9,9:16,4:3',
            'setting_video_room.title'   => 'nullable|string|max:200',

            'categories'          => "{$required}|array|min:1",
            'categories.*'        => 'integer|exists:categories,id',
            'tags'                => 'nullable|array',
            'tags.*'              => 'integer|exists:tags,id',
            'additional_services' => 'nullable|array',
            'additional_services.*' => 'integer|exists:additional_services,id',

            'image_main'          => ($isUpdate ? 'sometimes|' : 'required|') . self::IMAGE_RULE,
            'gallery'             => 'nullable|array|max:8',
            'gallery.*'           => self::IMAGE_RULE,

            'partner_id'          => 'nullable|uuid|exists:partners,id',
        ];
    }

    // Chi nhánh/khu vực gán cho phòng phải nằm trong phạm vi user được phép chọn (khớp
    // modifyQueryUsing của SelectTree 'categories' trong ProductForm) — không cho user thường gán
    // phòng vào chi nhánh ngoài quyền hạn của mình dù họ biết đúng category_id.
    private function validateCategoryIds(User $user, array $categoryIds): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $allowed = $user->visibleProductCategoryIds();
        $invalid = array_diff($categoryIds, $allowed);

        if (! empty($invalid)) {
            return 'Không có quyền gán phòng vào chi nhánh/khu vực: ' . implode(', ', $invalid) . '.';
        }

        return null;
    }

    // Dịch vụ bổ sung gán cho phòng phải thuộc đúng đối tác của phòng (khớp modifyQueryUsing của
    // additionalServices trong ProductForm).
    private function validateServiceIds(?string $partnerId, array $serviceIds): ?string
    {
        $validCount = AdditionService::whereIn('id', $serviceIds)
            ->where('partner_id', $partnerId)
            ->count();

        if ($validCount !== count($serviceIds)) {
            return 'Một hoặc nhiều dịch vụ bổ sung không thuộc đối tác của phòng này.';
        }

        return null;
    }

    // Giới hạn tổng ảnh gallery (ảnh cũ + ảnh mới) PHẢI được kiểm tra TRƯỚC khi gọi hàm này (xem
    // store()/update()) — không kiểm tra ở đây vì gọi getMedia() tại đây (SAU khi đã thêm image_main
    // nhưng TRƯỚC khi thêm gallery) sẽ vô tình cache stale relation 'media' trên $product, khiến
    // các lần đọc getMedia()/getFirstMediaUrl() sau đó trong CÙNG request (vd toDetailItem()) không
    // thấy media mới vừa thêm — đã từng gây bug "tạo xong không thấy gallery" dù ảnh đã lưu đúng
    // trong DB. Hàm này chỉ đơn thuần ghi ảnh, không đọc lại media giữa chừng.
    private function handleImages(Request $request, Product $product): void
    {
        if ($request->hasFile('image_main')) {
            $product->clearMediaCollection('Ảnh bìa');
            $product->addMediaFromRequest('image_main')->toMediaCollection('Ảnh bìa');
        }

        if ($request->hasFile('gallery')) {
            foreach ($request->file('gallery') as $file) {
                $product->addMedia($file)->toMediaCollection('Thư viện');
            }
        }
    }

    private function visibleProductsQuery(User $user): Builder
    {
        $query = Product::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query;
    }

    // Mở rộng 1 category (chi nhánh gốc HOẶC khu vực con) ra chính nó + toàn bộ danh mục con.
    private function expandCategoryIds(int $categoryId): array
    {
        $allIds       = [$categoryId];
        $currentLevel = [$categoryId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }

    private function toListItem(Product $product): array
    {
        return [
            'id'         => $product->id,
            'name'       => $product->name,
            'image_main' => $product->getFirstMediaUrl('Ảnh bìa') ?: null,
            'categories' => $product->categories->map(fn (Category $c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values(),
            'status'     => $product->is_activated,
        ];
    }

    private function toDetailItem(Product $product): array
    {
        $video = $product->setting_video_room ?? [];

        return [
            'id'                  => $product->id,
            'name'                => $product->name,
            'slug'                => $product->slug,
            'wifi'                => $product->wifi,
            'home_code'           => $product->home_code,
            'address'             => $product->address,
            'latitude'            => $product->latitude,
            'longitude'           => $product->longitude,
            // Cột map_url đã bị xoá khỏi bảng products (thay bằng latitude/longitude) — tự dựng lại
            // link Google Maps từ toạ độ thay vì đọc cột không còn tồn tại.
            'map_url'             => ($product->latitude !== null && $product->longitude !== null)
                ? "https://www.google.com/maps?q={$product->latitude},{$product->longitude}"
                : null,
            'hotline'             => $product->hotline,
            'styles'              => (int) $product->styles,
            'short_description'   => $product->short_description,
            'description'         => $product->description,
            'video'               => [
                'url'   => $video['url'] ?? null,
                'ratio' => $video['ratio'] ?? null,
                'title' => $video['title'] ?? null,
            ],
            'image_main'          => $product->getFirstMediaUrl('Ảnh bìa') ?: null,
            'gallery'             => $product->getMedia('Thư viện')->map(fn ($media) => [
                'id'  => $media->id,
                'url' => $media->getUrl(),
            ])->values(),
            'categories'          => $product->categories->map(fn (Category $c) => [
                'id'        => $c->id,
                'name'      => $c->name,
                'slug'      => $c->slug,
                'parent_id' => $c->parent_id,
            ])->values(),
            'tags'                => $product->tags->map(fn (Tag $t) => [
                'id'   => $t->id,
                'name' => $t->name,
            ])->values(),
            'additional_services' => $product->additionalServices->map(fn (AdditionService $s) => [
                'id'        => $s->id,
                'name'      => $s->name,
                'price'     => $s->price,
                'image'     => $s->image,
                'image_url' => $s->image ? Storage::disk('public')->url($s->image) : null,
            ])->values(),
            'status'              => $product->is_activated,
            'created_at'          => $product->created_at,
            'updated_at'          => $product->updated_at,
        ];
    }
}
