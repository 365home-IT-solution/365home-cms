<?php

namespace Modules\BladeThemeV1\Support;

use App\Models\ProvinceBranch;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class BranchBookConfig
{
    // URL rút gọn (segment đầu path) <=> RoomType.slug thật trong DB (RoomSearchService đọc
    // ?type= = RoomType.slug, KHÔNG phải slug URL đẹp này — xem RoomSearchService::applyFilters()).
    // Đây là NGUỒN DUY NHẤT của mapping — mọi nơi cần đổi qua lại giữa 2 dạng slug (route/{type},
    // JS getTypeParam()/TYPE_URL_MAP, redirect canonical...) đều phải đi qua typeUrlSlugs()/
    // typeDbSlugFromUrl()/urlSlugFromTypeDbSlug() thay vì tự map tay, để không bao giờ lệch nhau.
    private const TYPE_URL_MAP = [
        'homestay'   => 'homestay',
        'khach-san'  => 'hotel',
        'mini-house' => 'mini_house',
        'villa'      => 'villa',
        'nha-nghi'   => 'motel',
        'chung-cu'   => 'apartment',
    ];

    // Slug chi nhánh (Category.slug) CŨ -> MỚI, dùng khi rút gọn slug từ "tên đầy đủ = địa chỉ"
    // (vd "254-xuan-thuy-an-binh-can-tho") sang dạng ngắn cho SEO (vd "254-xuan-thuy"). Category
    // tra theo slug hiện tại nên đổi slug xong là URL cũ 404 ngay nếu không có map này — chỉ
    // renderBookingBoard()/BladeThemeV1Controller cần tới (route chi tiết phòng tự lành nhờ so
    // sánh với slug MỚI mỗi request, xem renderProductDetail()). Thêm dòng mới mỗi khi đổi slug 1
    // chi nhánh, KHÔNG xoá dòng cũ — giữ redirect vĩnh viễn cho URL đã index/chia sẻ trước đó.
    public const LEGACY_BRANCH_SLUGS = [
        '254-xuan-thuy-an-binh-can-tho'     => '254-xuan-thuy',
        '252-xuan-thuy-an-binh-can-tho'     => '252-xuan-thuy',
        '69-d-nguyen-ngoc-bich-kdc-hung-phu-1' => '69-nguyen-ngoc-bich',
        '385v314b-385-d-tran-nam-phu'       => '385v3-14b-tran-nam-phu',
        '89-xuan-thuy-an-binh-can-tho'      => '89-xuan-thuy',
        '290-vo-van-kiet-soc-trang-can-tho' => '290-vo-van-kiet',
    ];

    /** Danh sách slug URL hợp lệ — dùng làm ->where('type', ...) cho route {type}. */
    public static function typeUrlSlugs(): array
    {
        return array_keys(self::TYPE_URL_MAP);
    }

    /** Slug URL (vd 'khach-san') → RoomType.slug thật trong DB (vd 'hotel'). */
    public static function typeDbSlugFromUrl(string $urlSlug): ?string
    {
        return self::TYPE_URL_MAP[$urlSlug] ?? null;
    }

    /** RoomType.slug thật trong DB (vd 'hotel') → slug URL rút gọn (vd 'khach-san'). */
    public static function urlSlugFromTypeDbSlug(?string $typeDbSlug): ?string
    {
        if (! $typeDbSlug) {
            return null;
        }
        $found = array_search($typeDbSlug, self::TYPE_URL_MAP, true);

        return $found !== false ? $found : null;
    }

    /**
     * Loại hình + chi nhánh + khu vực (province) mà 1 phòng thuộc về — dùng cho URL canonical
     * /{type}/{location}/{branch}/{slug} của trang chi tiết phòng (BladeThemeV1Controller::
     * renderProductDetail()). Loại hình lấy TRỰC TIẾP từ chính phòng ($product->roomType) — ĐÚNG
     * và đơn giản hơn suy ra qua chi nhánh (dù trên dữ liệu thật mỗi chi nhánh hiện chỉ có 1 loại
     * hình duy nhất, xem resolveTypeAndLocationForBranch()). $product->categories phải được
     * eager-load trước khi gọi. Mỗi phòng chỉ nên thuộc đúng 1 chi nhánh (kiểm tra thực tế trên dữ
     * liệu: 0/59 phòng gắn >1 chi nhánh) — lấy chi nhánh ĐẦU TIÊN tìm thấy nếu lỡ có nhiều hơn.
     */
    public static function resolveLocationForProduct(Product $product): ?array
    {
        $branchCatIds = ProvinceBranch::pluck('categorie_id')->unique()->values();
        if ($branchCatIds->isEmpty()) {
            return null;
        }

        $childToBranch = Category::whereIn('parent_id', $branchCatIds)->pluck('parent_id', 'id');

        $branchCatId = null;
        foreach ($product->categories as $cat) {
            if ($branchCatIds->contains($cat->id)) {
                $branchCatId = $cat->id;
                break;
            }
            if (isset($childToBranch[$cat->id])) {
                $branchCatId = $childToBranch[$cat->id];
                break;
            }
        }

        if (! $branchCatId) {
            return null;
        }

        $provinceBranch = ProvinceBranch::where('categorie_id', $branchCatId)
            ->with(['province', 'category'])
            ->first();

        if (! $provinceBranch || ! $provinceBranch->province || ! $provinceBranch->category) {
            return null;
        }

        $roomType   = $product->roomType;
        $typeUrlSlug = self::urlSlugFromTypeDbSlug($roomType?->slug);
        if (! $typeUrlSlug) {
            return null;
        }

        return [
            'type_url_slug' => $typeUrlSlug,
            'type_name'     => $roomType->name,
            'province_slug' => $provinceBranch->province->slug,
            'branch_slug'   => $provinceBranch->category->slug,
            'province_name' => $provinceBranch->province->name,
            'branch_name'   => $provinceBranch->category->name,
        ];
    }

    /**
     * Loại hình + khu vực của 1 CHI NHÁNH (không phải phòng) — dùng cho URL canonical
     * /{type}/{location}/{branch} của trang chi tiết chi nhánh (BladeThemeV1Controller::
     * renderBookingBoard()). Chi nhánh không có cột "loại hình" riêng — suy ra bằng loại hình
     * CHIẾM ĐA SỐ trong các phòng đang active của chi nhánh đó (trên dữ liệu thật, cả 7 chi nhánh
     * hiện tại đều thuần 1 loại hình duy nhất — "đa số" chỉ để phòng hờ trường hợp trộn loại hình
     * trong tương lai, không đổi kết quả với dữ liệu hiện tại).
     */
    public static function resolveTypeAndLocationForBranch(Category $branch): ?array
    {
        $childIds  = Category::where('parent_id', $branch->id)->pluck('id');
        $allCatIds = $childIds->push($branch->id);

        $typeDbSlug = Product::where('is_activated', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allCatIds))
            ->whereNotNull('room_type_id')
            ->with('roomType:id,slug')
            ->get()
            ->pluck('roomType.slug')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $typeUrlSlug = self::urlSlugFromTypeDbSlug($typeDbSlug);
        if (! $typeUrlSlug) {
            return null;
        }

        $provinceBranch = ProvinceBranch::where('categorie_id', $branch->id)
            ->with('province')
            ->first();

        if (! $provinceBranch || ! $provinceBranch->province) {
            return null;
        }

        return [
            'type_url_slug' => $typeUrlSlug,
            'province_slug' => $provinceBranch->province->slug,
        ];
    }

    /**
     * URL trang chi tiết 1 phòng (hoặc dịch vụ) — dùng chung cho mọi nơi render card sản phẩm
     * (components/products/{card,minimal,list-page,overlay}.blade.php). Sản phẩm type=service vẫn
     * đi route cũ (template.detail); phòng (type=simple) dùng URL canonical
     * /{type}/{location}/{branch}/{slug} khi xác định được loại hình + chi nhánh, rơi về
     * /room/{slug}/ khi không — server tự 301 sang canonical nếu có (xem
     * BladeThemeV1Controller::renderProductDetail()). $product->categories phải được eager-load
     * trước khi gọi (bỏ qua với type=service, không cần).
     */
    public static function resolveProductUrl(Product $product): string
    {
        if ($product->type === 'service') {
            return route('template.detail', ['slug' => $product->slug]);
        }

        $loc = self::resolveLocationForProduct($product);

        return $loc
            ? url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $product->slug . '/')
            : url('/room/' . $product->slug . '/');
    }
    /**
     * Chi nhánh (Category gốc) + config cho Livewire Book component (danh sách phòng khả dụng
     * theo khung giờ). Dùng chung cho trang chi tiết chi nhánh (/branch/{slug}) và panel đặt
     * lịch inline ở trang tìm kiếm (?view=branches) để 2 nơi luôn lọc phòng giống hệt nhau.
     */
    public static function build(string $slug): ?array
    {
        $branch = Category::where('slug', $slug)->first();

        if (! $branch) {
            return null;
        }

        $categoryIds = array_merge(
            [$branch->id],
            Category::where('parent_id', $branch->id)->pluck('id')->toArray()
        );

        $productIds = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where(function ($q) {
                $q->where('styles', 1)->orWhereNull('styles');
            })
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->whereHas('roomTimeSlots.timeSlot')
            ->orderBy('sort_order')
            ->get(['id'])
            ->pluck('id')
            ->values()
            ->toArray();

        return [
            'branch' => $branch,
            'bookConfig' => [
                'bookable_room_count' => count($productIds),
                'component' => [
                    'choose_room' => json_encode([
                        'categories' => [
                            [
                                'category_id' => $branch->id,
                                'products' => $productIds,
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'title_booking' => 'Đặt phòng ' . $branch->name,
                    'sub_title_booking' => 'Chọn phòng và mốc thời gian phù hợp tại chi nhánh này.',
                    'image_event' => '',
                ],
            ],
        ];
    }

    /**
     * Config rỗng để mount trước Book component khi chưa có chi nhánh nào được chọn
     * (panel đặt lịch inline ở trang ?view=branches).
     */
    public static function empty(): array
    {
        return [
            'bookable_room_count' => 0,
            'component' => [
                'choose_room' => json_encode(['categories' => []], JSON_UNESCAPED_UNICODE),
                'title_booking' => '',
                'sub_title_booking' => '',
                'image_event' => '',
            ],
        ];
    }
}
