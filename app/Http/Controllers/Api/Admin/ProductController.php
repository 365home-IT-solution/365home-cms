<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GeneratesUniqueSlug;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions\RoomCleaningAction;
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
 *    hotline/styles/room_type_id (Danh mục phòng — xem RoomTypeController, nullable), short_description/
 *    description, setting_video_room{url,ratio,title}, image_main (collection "Ảnh bìa"),
 *    gallery[] (collection "Thư viện").
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

    // Nhãn hiển thị cho occupancy_status — tính từ order_items/orders chồng lấn ngày tham chiếu
    // (xem resolveOccupancyStatuses()), KHÔNG liên quan tới is_activated (param 'status' ở trên).
    private const OCCUPANCY_STATUS_LABELS = [
        'empty'    => 'Phòng trống',
        'upcoming' => 'Sắp nhận',
        'staying'  => 'Đang ở',
        'overtime' => 'Hết giờ',
        'deposit'  => 'Đã đặt cọc',
    ];

    // Độ ưu tiên khi 1 phòng có nhiều đơn chồng lấn cùng 1 ngày tham chiếu — số càng lớn càng ưu
    // tiên. 'deposit' luôn thắng các trạng thái theo thời gian khác (yêu cầu nghiệp vụ: admin cần
    // thấy ngay phòng nào đang chờ thu nốt tiền cọc, bất kể đã tới giờ nhận/đang ở/hết giờ hay chưa).
    private const OCCUPANCY_STATUS_PRIORITY = [
        'deposit'  => 4,
        'overtime' => 3,
        'staying'  => 2,
        'upcoming' => 1,
    ];

    // Giá trị hợp lệ cho filter front_desk_status — khớp 4 chỉ số ở widget "LỄ TÂN" trên dashboard
    // (xem docblock DashboardController::frontDesk()/OverviewService::frontDesk()).
    private const FRONT_DESK_STATUSES = ['checked_in', 'checked_out', 'has_guest', 'needs_cleaning'];

    /**
     * GET /api/admin/products
     * Danh sách phòng — ảnh chính, tên, chi nhánh (categories), giá, trạng thái. Field 'price' xem
     * docblock toListItem()/firstSlotPriceMap() — styles=1 lấy giá khung giờ đầu tiên trong ngày,
     * styles=2 lấy thẳng products.price.
     * Query params:
     *  - category_id      : lọc theo 1 chi nhánh/khu vực (gồm cả khu vực con nếu truyền chi nhánh gốc)
     *  - categories       : slug của 1 chi nhánh CHA (parent_id=null, category_type=product — giống
     *                       GET /api/admin/rooms?categories=) — lọc theo chi nhánh đó (gồm khu vực
     *                       con). Dùng độc lập hoặc CÙNG lúc với category_id (kết hợp AND); slug
     *                       không khớp chi nhánh nào -> trả rỗng thay vì bỏ qua filter.
     *  - search           : lọc theo tên phòng HOẶC tên chi nhánh
     *  - room_type_id     : lọc theo Danh mục phòng (products.room_type_id)
     *  - styles           : 1 = phòng theo khung giờ, 2 = phòng theo ngày
     *  - occupancy_status : lọc theo trạng thái sử dụng phòng tại ngày tham chiếu — 1 hoặc nhiều giá
     *                       trị trong empty|upcoming|staying|overtime|deposit (mảng occupancy_status[]=
     *                       hoặc chuỗi phân tách dấu phẩy). Xem resolveOccupancyStatuses() để biết logic.
     *                       ĐỘC LẬP với 'room_clean' bên dưới — phòng 'overtime' (hết giờ) vẫn giữ
     *                       nguyên occupancy_status='overtime' dù housekeeping_status đã chuyển
     *                       'cleaning' (không gộp 2 khái niệm này làm 1).
     *  - front_desk_status : lọc theo 1 trong 4 chỉ số widget "LỄ TÂN" — checked_in (đã nhận trong
     *                       ngày tham chiếu) | checked_out (đã trả trong ngày tham chiếu) | has_guest
     *                       (đang có khách — ảnh chụp tức thời, KHÔNG theo ngày tham chiếu) |
     *                       needs_cleaning (housekeeping_status='cleaning', đang cần dọn). Dùng CÙNG
     *                       'date' bên dưới cho checked_in/checked_out; kết hợp AND với occupancy_status
     *                       nếu truyền cả 2 (2 filter độc lập, không loại trừ nhau).
     *  - date             : ngày tham chiếu (Y-m-d) cho occupancy_status. Nếu vắng, ghép từ day/month/
     *                       year (phần nào thiếu lấy theo hôm nay); nếu không truyền gì → mặc định hôm nay.
     *  - per_page         : mặc định 20
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Product::query()->with(['categories:id,name,slug,parent_id', 'roomType:id,name']);

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        if ($request->filled('category_id')) {
            $categoryIds = $this->expandCategoryIds((int) $request->integer('category_id'));
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
        }

        if ($request->filled('categories')) {
            $branch = Category::query()
                ->whereNull('parent_id')
                ->where('category_type', 'product')
                ->where('slug', (string) $request->string('categories'))
                ->first();

            if (! $branch) {
                // Slug không khớp chi nhánh nào -> ép query rỗng nhưng vẫn đi qua paginate() bên
                // dưới để giữ nguyên shape phân trang (khác GET /api/admin/rooms trả thẳng {data:[]}).
                $query->whereRaw('1 = 0');
            } else {
                $branchCategoryIds = $this->expandCategoryIds($branch->id);
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $branchCategoryIds));
            }
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('categories', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        // Phòng đã ẩn (is_activated=false, toggle "Ẩn" ở Filament/PATCH rooms/{id}/status) không bao
        // giờ xuất hiện ở danh sách này — không có param nào bật lại việc hiển thị chúng ở đây.
        $query->where('is_activated', true);

        if ($request->filled('room_type_id')) {
            $query->where('room_type_id', $request->integer('room_type_id'));
        }

        if ($request->filled('styles')) {
            if (! in_array($request->input('styles'), ['1', '2', 1, 2], true)) {
                return response()->json(['message' => 'styles chỉ nhận giá trị 1 (khung giờ) hoặc 2 (theo ngày).'], 422);
            }
            $query->where('styles', $request->integer('styles'));
        }

        $targetDate = $this->resolveOccupancyDate($request);

        if ($request->filled('front_desk_status')) {
            $frontDeskStatus = $request->string('front_desk_status')->toString();
            if (! in_array($frontDeskStatus, self::FRONT_DESK_STATUSES, true)) {
                return response()->json(['message' => 'front_desk_status không hợp lệ: '.$frontDeskStatus.'. Chỉ nhận: '.implode(', ', self::FRONT_DESK_STATUSES)], 422);
            }

            $dayStart = $targetDate->copy()->startOfDay();
            $dayEnd   = $targetDate->copy()->endOfDay();
            $now      = Carbon::now();

            match ($frontDeskStatus) {
                // "Đã nhận" — đơn có phòng này được check-in trong ngày tham chiếu (mặc định hôm nay).
                'checked_in' => $query->whereHas('orderItems.order', fn ($q) => $q
                    ->where('exclude_from_stats', false)
                    ->whereBetween('checked_in_at', [$dayStart, $dayEnd])),
                // "Đã trả" — lịch trả phòng (order_items.checkout_date) rơi vào ngày tham chiếu, cùng
                // cách tính với OverviewService::frontDesk()::checked_out_count.
                'checked_out' => $query->whereHas('orderItems', fn ($q) => $q
                    ->whereBetween('checkout_date', [$dayStart, $dayEnd])
                    ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false))),
                // "Có khách" — đang trong khoảng lưu trú TẠI THỜI ĐIỂM GỌI API (ảnh chụp tức thời,
                // không theo ngày tham chiếu) — cùng công thức OverviewService::frontDesk()::occupied_rooms.
                'has_guest' => $query->whereHas('orderItems', fn ($q) => $q
                    ->where('checkin_date', '<=', $now)
                    ->where('checkout_date', '>=', $now)
                    ->whereHas('order', fn ($o) => $o->where('exclude_from_stats', false))),
                // "Cần dọn" — đọc thẳng housekeeping_status='cleaning' (tự động bật bởi lệnh
                // housekeeping:mark-cleaning khi hết giờ đơn, xem MarkRoomsForCleaningCommand).
                'needs_cleaning' => $query->where('housekeeping_status', 'cleaning'),
            };
        }

        $occupancyFilter = null;
        if ($request->filled('occupancy_status')) {
            $raw    = $request->input('occupancy_status');
            $values = array_values(array_unique(array_filter(array_map(
                'trim',
                is_array($raw) ? $raw : explode(',', (string) $raw)
            ))));
            $invalid = array_diff($values, array_keys(self::OCCUPANCY_STATUS_LABELS));
            if (! empty($invalid)) {
                return response()->json(['message' => 'occupancy_status không hợp lệ: ' . implode(', ', $invalid)], 422);
            }
            $occupancyFilter = $values;
        }

        // $statusMap được xây dần: nếu có filter occupancy_status, tính trước cho TOÀN BỘ id thoả các
        // filter khác (chưa phân trang) để lọc đúng trước khi paginate(); phần id còn thiếu (trang
        // hiện tại, khi không lọc theo occupancy_status) được bổ sung ngay sau paginate() bên dưới —
        // tránh tính occupancy 2 lần cho cùng 1 id.
        $statusMap = [];
        if ($occupancyFilter !== null) {
            $candidateIds = (clone $query)->pluck('id')->all();
            $statusMap    = $this->resolveOccupancyStatuses($candidateIds, $targetDate);

            $matchingIds = array_keys(array_filter(
                $statusMap,
                fn (string $status) => in_array($status, $occupancyFilter, true)
            ));
            // Phòng không có đơn nào chồng lấn ngày tham chiếu sẽ KHÔNG xuất hiện trong $statusMap
            // (resolveOccupancyStatuses() chỉ trả về phòng có ít nhất 1 đơn liên quan) — coi là 'empty'.
            if (in_array('empty', $occupancyFilter, true)) {
                $matchingIds = array_unique(array_merge(
                    $matchingIds,
                    array_diff($candidateIds, array_keys($statusMap))
                ));
            }
            $query->whereIn('id', $matchingIds);
        }

        $products = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        $pageIds    = $products->getCollection()->pluck('id')->all();
        $missingIds = array_diff($pageIds, array_keys($statusMap));
        if (! empty($missingIds)) {
            $statusMap += $this->resolveOccupancyStatuses($missingIds, $targetDate);
        }

        $slotStyleIds      = $products->getCollection()->filter(fn (Product $p) => (int) $p->styles === 1)->pluck('id')->all();
        $firstSlotPriceMap = $this->firstSlotPriceMap($slotStyleIds);

        $products->getCollection()->transform(
            fn (Product $p) => $this->toListItem($p, $statusMap[$p->id] ?? 'empty', $firstSlotPriceMap[$p->id] ?? null)
        );

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
            ->with(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image', 'roomType:id,name,slug'])
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
                'room_type_id'        => $data['room_type_id'] ?? null,
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
        $product = $product->fresh(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image', 'roomType:id,name,slug']);

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
                'styles', 'room_type_id', 'short_description', 'description', 'setting_video_room',
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

        $product->load(['categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image', 'roomType:id,name,slug']);

        return response()->json(['data' => $this->toDetailItem($product->fresh([
            'categories:id,name,slug,parent_id', 'tags', 'additionalServices:id,name,price,image', 'roomType:id,name,slug',
        ]))]);
    }

    /**
     * PATCH /api/admin/rooms/{id}/status
     * Bật/tắt trạng thái hoạt động của phòng. Body dùng field "status" (khớp field cùng tên trả
     * về ở toListItem()/toDetailItem()) — map xuống cột is_activated trong DB.
     * Body: status (required|boolean).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
            'status' => 'required|boolean',
        ]);

        $product->update(['is_activated' => $data['status']]);

        return response()->json(['message' => 'Đã cập nhật trạng thái.']);
    }

    /**
     * PATCH /api/admin/rooms/{id}/room-type
     * Gán/đổi Danh mục phòng (bảng room_types, xem RoomTypeController) cho 1 phòng — map xuống cột
     * products.room_type_id (nullable, nullOnDelete khi danh mục bị xoá). Body: room_type_id
     * (present|nullable|integer|exists:room_types,id) — truyền null để gỡ danh mục khỏi phòng.
     */
    public function updateRoomType(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
            'room_type_id' => 'present|nullable|integer|exists:room_types,id',
        ]);

        $product->update(['room_type_id' => $data['room_type_id']]);

        return response()->json(['message' => 'Đã gán danh mục phòng.']);
    }

    /**
     * DELETE /api/admin/rooms/{id}/room-type/{roomTypeId}
     * Gỡ danh mục phòng khỏi 1 phòng — YÊU CẦU đúng roomTypeId đang gán (khác PATCH .../room-type
     * với room_type_id=null là gỡ VÔ ĐIỀU KIỆN), tránh gỡ nhầm nếu client đang cầm state cũ (vd đã
     * bị đổi sang danh mục khác ở nơi khác trước khi client kịp gọi xoá). products.room_type_id là
     * cột đơn (belongsTo), 1 phòng CHỈ thuộc đúng 1 danh mục — không có khái niệm "gỡ 1 trong nhiều
     * danh mục đang gán" như promotions (RoomPromotionController).
     */
    public function destroyRoomType(Request $request, string $id, int $roomTypeId): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ((int) $product->room_type_id !== $roomTypeId) {
            return response()->json(['message' => 'Phòng hiện không gán danh mục này.'], 404);
        }

        $product->update(['room_type_id' => null]);

        return response()->json(['message' => 'Đã gỡ danh mục phòng.']);
    }

    /**
     * PATCH /api/admin/rooms/{id}/confirm-cleaning
     * Nhân viên xác nhận đã dọn xong — chuyển housekeeping_status 'cleaning' → 'available', ghi
     * nhận vào room_cleaning_logs (employee_id/cleaned_at/note). Tái dùng ĐÚNG logic + phân quyền
     * đang chạy ở Filament (Product::confirmCleaned(), RoomCleaningAction::canManageCleaning()) —
     * không cài lại. Quyền: super_admin/chủ đối tác, hoặc nhân viên trực ĐÚNG chi nhánh của phòng
     * này (works_all_branches hoặc workBranches() giao đúng chi nhánh phòng thuộc về).
     * Body: note (nullable|string).
     */
    public function confirmCleaning(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $product = $this->visibleProductsQuery($user)->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        if ($product->housekeeping_status !== 'cleaning') {
            return response()->json(['message' => 'Phòng hiện không ở trạng thái cần dọn.'], 422);
        }

        if (! RoomCleaningAction::canManageCleaning($product)) {
            return response()->json(['message' => 'Không có quyền xác nhận dọn phòng này.'], 403);
        }

        $employee = $user->employee;

        // super_admin/chủ đối tác không bắt buộc có hồ sơ Employee (2 vai trò này thường không gắn
        // với đối tác cụ thể nào) — vẫn xác nhận được, log chỉ không ghi được employee_id. Nhân viên
        // thường (không phải 2 vai trò trên) vẫn buộc phải có hồ sơ Employee mới xác nhận được.
        if (! $employee && ! $user->isSuperAdmin() && ! $user->isPartnerOwner()) {
            return response()->json(['message' => 'Tài khoản này chưa có hồ sơ nhân viên liên kết — không thể xác nhận.'], 422);
        }

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $product->confirmCleaned($employee, $data['note'] ?? null);

        return response()->json(['message' => 'Đã xác nhận dọn xong — phòng sẵn sàng nhận đặt mới.']);
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

    /**
     * PATCH /api/admin/rooms/{id}/booking-settings
     * Cấu hình đặt phòng cho 1 phòng — gồm điều kiện giảm giá (full_booking_discount/
     * bulk_discount_rules/room_config, giống discountSettings() nhưng cho 1 phòng thay vì bulk
     * room_ids[]) VÀ cọc/giờ nhận-trả mặc định (deposit_1_night/deposit_multi_night/
     * deposit_min_nights/default_checkin/default_checkout) — các field này tồn tại sẵn trên bảng
     * products nhưng trước nay chỉ sửa được qua Filament (SettingBook), chưa có API REST.
     * Field nào không có mặt trong request giữ nguyên giá trị cũ. KHÔNG giới hạn cứng field nào
     * theo styles (API vẫn nhận và lưu mọi field bất kể styles) nhưng theo code tính giá thực tế
     * (RoomDiscountCalculator, BuildsRoomBooking) từng field chỉ THỰC SỰ có tác dụng ở đúng 1 style:
     *  - full_booking_discount, bulk_discount_rules : CHỈ styles=1 (khung giờ) — full_booking_discount
     *    áp dụng khi đặt HẾT toàn bộ khung giờ trong ngày (checkFullDayBooking()); bulk_discount_rules
     *    giảm theo SỐ KHUNG GIỜ đặt (applyBulkDiscount()). Cấu trúc mỗi rule: {"slots":3,"discount":10}
     *    (khớp field name Filament SettingBook ghi — KHÔNG phải "min_slots"/"discount_percent").
     *  - room_config{max_free_guests,extra_guest_fee} : CẢ 2 styles (buildGuestSurcharge() tính phụ
     *    thu khách cho cả type=slot lẫn type=daily).
     *  - deposit_1_night, deposit_multi_night, deposit_min_nights, default_checkin, default_checkout :
     *    CHỈ styles=2 (theo ngày) — chỉ được đọc trong nhánh $type === 'daily' khi build đơn.
     *
     * Body (mỗi field đều optional, cần ít nhất 1):
     *  - full_booking_discount (string, vd "10%"), bulk_discount_rules ([{"slots":N,"discount":pct}]),
     *    room_config{max_free_guests,extra_guest_fee}
     *  - deposit_1_night, deposit_multi_night (0-100, % cọc), deposit_min_nights (>=1)
     *  - default_checkin, default_checkout (H:i)
     *
     * Lưu ý: nếu request có 'room_config', field này được MERGE NÔNG với room_config hiện có (không
     * ghi đè toàn bộ) — để không xoá mất key 'blocked_ranges' mà RoomBlockController ghi vào cùng
     * cột này cho phòng styles=2. Khác với discountSettings() (bulk) vẫn ghi đè toàn bộ room_config.
     */
    public function updateBookingSettings(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProductsQuery($request->user())->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
            'full_booking_discount'       => 'nullable|string|max:50',
            'bulk_discount_rules'         => 'nullable|array',
            'room_config'                 => 'nullable|array',
            'room_config.max_free_guests' => 'nullable|integer|min:0',
            'room_config.extra_guest_fee' => 'nullable|numeric|min:0',
            'deposit_1_night'             => 'nullable|integer|min:0|max:100',
            'deposit_multi_night'         => 'nullable|integer|min:0|max:100',
            'deposit_min_nights'          => 'nullable|integer|min:1',
            'default_checkin'             => 'nullable|date_format:H:i',
            'default_checkout'            => 'nullable|date_format:H:i',
        ]);

        $allowedFields = [
            'full_booking_discount', 'bulk_discount_rules', 'room_config',
            'deposit_1_night', 'deposit_multi_night', 'deposit_min_nights',
            'default_checkin', 'default_checkout',
        ];
        if (empty(array_intersect(array_keys($data), $allowedFields))) {
            return response()->json(['message' => 'Cần nhập ít nhất 1 field cần cập nhật.'], 422);
        }

        if (array_key_exists('room_config', $data)) {
            $existingRoomConfig = is_array($product->room_config) ? $product->room_config : [];
            $data['room_config'] = array_merge($existingRoomConfig, $data['room_config']);
        }

        $product->update(collect($data)->only($allowedFields)->toArray());

        return response()->json(['message' => 'Đã cập nhật cấu hình đặt phòng.']);
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
            'room_type_id'        => 'nullable|integer|exists:room_types,id',
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

    // $firstSlotPrice: giá khung giờ ĐẦU TIÊN trong ngày (start_time nhỏ nhất) — CHỈ có ý nghĩa với
    // phòng styles=1, lấy sẵn từ firstSlotPriceMap() (1 query gộp cho cả trang ở index(), tránh N+1).
    private function toListItem(Product $product, string $occupancyStatus = 'empty', ?float $firstSlotPrice = null): array
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
            'loai_phong'             => $product->roomType?->name,
            // styles=1 (khung giờ): giá khung giờ đầu tiên trong ngày (room_time_slots, xem
            // firstSlotPriceMap()); styles=2 (theo ngày): thẳng cột products.price.
            'price'                  => (int) $product->styles === 2
                ? ($product->price !== null ? (float) $product->price : null)
                : $firstSlotPrice,
            'status'                 => $product->is_activated,
            'occupancy_status'       => $occupancyStatus,
            'occupancy_status_label' => self::OCCUPANCY_STATUS_LABELS[$occupancyStatus],
            // room_clean: available (sạch, sẵn sàng đón khách) | cleaning (cần dọn) | maintenance
            // (đang bảo trì) — ĐỘC LẬP với occupancy_status ở trên (xem docblock index()), đọc thẳng
            // products.housekeeping_status, KHÔNG tính lại/gộp vào occupancy_status.
            'room_clean'             => $product->housekeeping_status,
        ];
    }

    // Giá của khung giờ ĐẦU TIÊN trong ngày (start_time nhỏ nhất) cho từng phòng styles=1 trong
    // $productIds — 1 query DUY NHẤT cho toàn bộ danh sách thay vì lặp N+1 theo từng phòng. Giống
    // hệt RoomTypeController::firstSlotPriceMap() (không tách trait dùng chung vì chỉ 2 nơi dùng).
    private function firstSlotPriceMap(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return \Modules\Product\App\Models\RoomTimeSlot::whereIn('room_time_slots.room_id', $productIds)
            ->whereNull('room_time_slots.date')
            ->whereNotNull('room_time_slots.price')
            ->join('time_slots', 'time_slots.id', '=', 'room_time_slots.timeslot_id')
            ->orderBy('time_slots.start_time')
            ->orderBy('room_time_slots.id')
            ->get(['room_time_slots.room_id as room_id', 'room_time_slots.price as price'])
            ->groupBy('room_id')
            ->map(fn ($rows) => (float) $rows->first()->price)
            ->all();
    }

    // Ghép ngày tham chiếu từ 'date' (Y-m-d) hoặc day/month/year (phần thiếu lấy theo hôm nay);
    // không truyền gì -> hôm nay. Dùng cho occupancy_status ở index().
    private function resolveOccupancyDate(Request $request): Carbon
    {
        if ($request->filled('date')) {
            try {
                return Carbon::createFromFormat('Y-m-d', (string) $request->string('date'))->startOfDay();
            } catch (\Exception) {
                return Carbon::now()->startOfDay();
            }
        }

        if ($request->filled('day') || $request->filled('month') || $request->filled('year')) {
            $today = Carbon::now();

            return Carbon::create(
                $request->integer('year', $today->year),
                $request->integer('month', $today->month),
                $request->integer('day', $today->day),
            )->startOfDay();
        }

        return Carbon::now()->startOfDay();
    }

    // Tính occupancy_status cho từng phòng trong $productIds tại ngày $date, dựa trên order_items/
    // orders CHỒNG LẤN ngày đó (span = MIN(checkin_date)..MAX(checkout_date) của CHÍNH các item thuộc
    // phòng đó trong 1 đơn — 1 đơn có thể đặt nhiều phòng khác nhau, mỗi phòng tính span riêng).
    // Bỏ qua đơn đã 'failed'/'cancelled_payment'/'refunded' (không chiếm chỗ) và đơn order_status đã
    // 'checked_out' (đã trả phòng, không còn 'đang ở'/'hết giờ'). Trả về [product_id => status_slug];
    // phòng KHÔNG có mặt trong kết quả nghĩa là không có đơn nào chồng lấn ngày đó (= 'empty').
    private function resolveOccupancyStatuses(array $productIds, Carbon $date): array
    {
        $productIds = array_values(array_unique($productIds));
        if (empty($productIds)) {
            return [];
        }

        $dayStart = $date->copy()->startOfDay();
        $dayEnd   = $date->copy()->endOfDay();
        $now      = Carbon::now();

        // Alias tường minh 'oi'/'o' — cần vì selectRaw()/havingRaw() bên dưới là chuỗi RAW, Laravel
        // KHÔNG tự thêm table prefix vào bên trong raw string như nó làm với whereIn()/groupBy(). Ở
        // môi trường này config('database...prefix') = 'cms_' và Laravel còn tự đổi CHÍNH alias
        // 'oi'/'o' thành 'cms_oi'/'cms_o' khi build "from cms_order_items as cms_oi" — nên phải tự
        // lấy prefix qua DB::getTablePrefix() rồi ghép vào alias dùng trong raw string cho khớp,
        // không thể chỉ viết chay 'oi.product_id' (sẽ lỗi "Unknown column" nếu có prefix).
        $prefix    = DB::getTablePrefix();
        $oiAlias   = $prefix . 'oi';
        $orderAlias = $prefix . 'o';

        $spans = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('oi.product_id', $productIds)
            ->whereNotNull('oi.checkin_date')
            ->whereNotNull('oi.checkout_date')
            ->whereNotIn('o.status', ['failed', 'cancelled_payment', 'refunded'])
            ->where(function ($q) {
                $q->whereNull('o.order_status')->orWhere('o.order_status', '!=', 'checked_out');
            })
            ->groupBy('oi.order_id', 'oi.product_id', 'o.status')
            ->havingRaw("MIN({$oiAlias}.checkin_date) <= ?", [$dayEnd])
            ->havingRaw("MAX({$oiAlias}.checkout_date) >= ?", [$dayStart])
            ->selectRaw("{$oiAlias}.product_id as product_id, {$orderAlias}.status as payment_status, "
                . "MIN({$oiAlias}.checkin_date) as span_start, MAX({$oiAlias}.checkout_date) as span_end")
            ->get();

        $result = [];
        foreach ($spans as $row) {
            $label = $this->classifyOccupancy(
                (string) $row->payment_status,
                Carbon::parse($row->span_start),
                Carbon::parse($row->span_end),
                $now,
            );

            $productId = (string) $row->product_id;
            if (! isset($result[$productId])
                || self::OCCUPANCY_STATUS_PRIORITY[$label] > self::OCCUPANCY_STATUS_PRIORITY[$result[$productId]]) {
                $result[$productId] = $label;
            }
        }

        return $result;
    }

    private function classifyOccupancy(string $paymentStatus, Carbon $spanStart, Carbon $spanEnd, Carbon $now): string
    {
        if ($paymentStatus === 'deposit') {
            return 'deposit';
        }
        if ($now->gt($spanEnd)) {
            return 'overtime';
        }
        if ($now->between($spanStart, $spanEnd)) {
            return 'staying';
        }

        return 'upcoming';
    }

    private function toDetailItem(Product $product): array
    {
        $video = $product->setting_video_room ?? [];

        return [
            'id'                  => $product->id,
            'name'                => $product->name,
            'slug'                => $product->slug,
            'wifi'                => $product->wifi,
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
            'room_type'           => $product->roomType ? [
                'id'   => $product->roomType->id,
                'name' => $product->roomType->name,
                'slug' => $product->roomType->slug,
            ] : null,
            'short_description'   => $product->short_description,
            'description'         => $product->description,
            'video'               => [
                'url' => $video['url'] ?? null,
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
