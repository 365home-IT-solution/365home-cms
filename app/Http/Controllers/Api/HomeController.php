<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Http\Concerns\ResolvesProvince;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\AppPage;
use Modules\AppPage\App\Models\Banner;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

class HomeController extends Controller
{
    use BuildsRoomCard, ResolvesProvince;

    public function __invoke(Request $request): JsonResponse
    {
        $page = AppPage::where('slug', 'home')
            ->where('is_active', true)
            ->first();

        if (! $page) {
            return response()->json(['message' => 'Home page not found.'], 404);
        }

        $tabRoomTypeId = $request->query('tab') !== null
            ? (int) $request->query('tab')
            : null;

        $province = $this->resolveProvince($request);

        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url'])
            ->toArray();

        $authUser      = auth('sanctum')->user();
        $wishlistedIds = $authUser
            ? $authUser->wishlists()->pluck('product_id')->toArray()
            : null;

        $sections = collect($page->content ?? [])
            ->filter(fn ($block) => in_array($block['type'] ?? '', ['banner', 'room_list', 'suggestion_list', 'promotion_list']))
            ->values()
            ->map(fn ($block, $index) => $this->buildBlock($block, $index, $wishlistedIds, $tabRoomTypeId, $province))
            ->filter()
            ->values();

        // Lịch đặt phòng trực tuyến (component riêng, không nằm trong $sections — luôn render
        // trước vòng lặp sections ở flash-sale.blade.php) → Flash Sale → Gợi ý theo phòng luôn
        // đứng NGAY SAU nó theo đúng thứ tự này, bất kể admin sắp xếp block thế nào trong CMS —
        // các block còn lại (danh sách phòng theo chi nhánh, gợi ý theo chi nhánh...) giữ nguyên
        // thứ tự tương đối, xếp sau. Chỉ ưu tiên khi 2 block này thực sự tồn tại trong CMS (nếu
        // admin không cấu hình thì không có phần tử nào khớp, sortBy không có tác dụng).
        $sections = $sections
            ->sortBy(function ($section) {
                if ($section['type'] === 'promotion_list') {
                    return 0;
                }
                if ($section['type'] === 'suggestion_list' && ($section['suggestion_type'] ?? null) === 'room') {
                    return 1;
                }

                return 2;
            })
            ->values()
            // homeSections().load() ở home-sections.js tự sort lại sections theo 'sort_order' sau
            // khi nhận response — sort_order được gán TỪ TRƯỚC theo vị trí gốc trong CMS (buildBlock()
            // ở trên), nên nếu không đánh số lại theo vị trí MỚI sau khi sắp xếp ở đây, phía client sẽ
            // âm thầm sort ngược lại về thứ tự CMS ban đầu, xóa mất tác dụng của sortBy() phía trên.
            ->map(function ($section, $i) {
                $section['sort_order'] = $i + 1;
                $section['id']         = $i + 1;

                return $section;
            });

        return response()->json([
            'home' => [
                'room_types' => $roomTypes,
                'sections'   => $sections,
            ],
        ]);
    }

    private function buildBlock(array $block, int $index, ?array $wishlistedIds, ?int $tabRoomTypeId, ?Province $province = null): ?array
    {
        return match ($block['type']) {
            'banner'          => $this->buildBanner($block['data'] ?? [], $index),
            'room_list'       => $this->buildRoomList($block['data'] ?? [], $index, $wishlistedIds, $tabRoomTypeId, $province),
            'suggestion_list' => $this->buildSuggestionList($block['data'] ?? [], $index, $wishlistedIds, $province),
            'promotion_list'  => $this->buildPromotionList($block['data'] ?? [], $index, $wishlistedIds, $province),
            default           => null,
        };
    }

    // ─── Banner ──────────────────────────────────────────────────────────────

    private function buildBanner(array $data, int $index): array
    {
        $bannerIds = collect($data['items'] ?? [])
            ->pluck('banner_id')
            ->filter()
            ->values()
            ->toArray();

        $bannersById = Banner::whereIn('id', $bannerIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $items = collect($bannerIds)
            ->map(fn ($id) => $bannersById->get($id))
            ->filter()
            ->map(fn (Banner $banner) => [
                'title'       => $banner->title,
                'description' => $banner->description,
                'image_url'   => $banner->image
                    ? Storage::disk($banner->disk ?? 'public')->url($banner->image)
                    : null,
                'thumbnail'   => $banner->thumbnail,
                'url'         => $banner->url,
            ])
            ->values()
            ->toArray();

        return [
            'type'       => 'banner',
            'id'         => $index + 1,
            'sort_order' => $index + 1,
            'items'      => $items,
        ];
    }

    // ─── Room list ───────────────────────────────────────────────────────────

    private function buildRoomList(array $data, int $index, ?array $wishlistedIds, ?int $tabRoomTypeId, ?Province $province = null): ?array
    {
        $displayMode = $data['display_mode'] ?? 'fixed';
        // Chỉ có ý nghĩa khi display_mode=by_region — fixed luôn là danh sách phòng.
        $contentType = $displayMode === 'by_region' ? ($data['region_content_type'] ?? 'rooms') : 'rooms';

        // by_region: ẩn section khi chưa có khu vực
        if ($displayMode === 'by_region' && $province === null) {
            return null;
        }

        $items = $contentType === 'branches'
            ? $this->getRegionBranches($province)
            : $this->getRooms($data, $displayMode, $wishlistedIds, $tabRoomTypeId, $province);

        // by_region: ẩn section khi khu vực không có phòng/chi nhánh
        if ($displayMode === 'by_region' && empty($items)) {
            return null;
        }

        return [
            'type'         => 'room_list',
            'id'           => $index + 1,
            'sort_order'   => $index + 1,
            'title'        => $data['title'] ?? null,
            'subtitle'     => $data['subtitle'] ?? null,
            'view_all_url' => $this->buildViewAllUrl($data, $displayMode, $province) ?? ($data['view_all_url'] ?? null),
            'show_arrow'   => (bool) ($data['show_arrow'] ?? true),
            'layout'       => $data['layout'] ?? 'horizontal_scroll',
            'display_mode' => $displayMode,
            'content_type' => $contentType,
            'rooms'        => $items,
        ];
    }

    // Danh sách chi nhánh của khu vực — dùng khi display_mode=by_region &&
    // region_content_type=branches. Shape khớp với SearchController::branches() để app tái dùng
    // component card chi nhánh đã có.
    private function getRegionBranches(?Province $province): array
    {
        if ($province === null) {
            return [];
        }

        return $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->filter(fn ($branch) => $branch->category && $branch->category->status)
            ->sortBy(fn ($branch) => $branch->category->sort_order)
            ->values()
            ->map(fn ($branch) => [
                'id'        => $branch->category->id,
                'name'      => $branch->category->name,
                'slug'      => $branch->category->slug,
                'image_url' => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
                'thumbnail' => $branch->category->thumbnail,
            ])
            ->values()
            ->toArray();
    }

    // "Xem tất cả" của 1 khối phòng nên đưa thẳng người dùng đến đúng phạm vi phòng mà khối đó
    // đang hiển thị: theo khu vực (tỉnh đang chọn) hoặc theo (các) chi nhánh cụ thể đã cấu hình —
    // thay vì luôn trỏ về trang tìm kiếm chung chung không có bộ lọc.
    private function buildViewAllUrl(array $data, string $displayMode, ?Province $province): ?string
    {
        if ($displayMode === 'by_region') {
            return $province ? '/s/' . $province->slug : null;
        }

        $branchIds = array_filter((array) ($data['branch_ids'] ?? []));
        if (empty($branchIds)) {
            return null;
        }

        $slugs = Category::whereIn('id', $branchIds)->pluck('slug');

        return $slugs->isNotEmpty() ? '/s/' . $slugs->implode(',') : null;
    }

    private function getRooms(array $data, string $displayMode, ?array $wishlistedIds, ?int $tabRoomTypeId, ?Province $province = null): array
    {
        $productIds = $data['product_ids'] ?? [];

        if (! empty($productIds)) {
            // Phòng được chọn tay — fixed: lấy hết; by_region: lọc theo tỉnh
            $query = Product::whereIn('id', $productIds)
                ->where('is_activated', true)
                ->where('is_in_stock', true);

            if ($displayMode === 'by_region' && $province !== null) {
                $provinceBranchIds = $province->branches()
                    ->where('status', true)
                    ->pluck('categorie_id')
                    ->toArray();

                if (! empty($provinceBranchIds)) {
                    $childIds  = Category::whereIn('parent_id', $provinceBranchIds)->pluck('id');
                    $filterIds = collect($provinceBranchIds)->merge($childIds)->unique()->values();
                    $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        } else {
            $query = Product::where('is_activated', true)
                ->where('is_in_stock', true);

            $branchIds = array_filter((array) ($data['branch_ids'] ?? []));

            if ($displayMode === 'by_region' && $province !== null) {
                $provinceBranchIds = $province->branches()
                    ->where('status', true)
                    ->pluck('categorie_id')
                    ->toArray();

                $effectiveBranchIds = ! empty($branchIds)
                    ? array_values(array_intersect($branchIds, $provinceBranchIds))
                    : $provinceBranchIds;

                if (! empty($effectiveBranchIds)) {
                    $childIds  = Category::whereIn('parent_id', $effectiveBranchIds)->pluck('id');
                    $filterIds = collect($effectiveBranchIds)->merge($childIds)->unique()->values();
                    $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif (! empty($branchIds)) {
                // fixed + branch_ids: lọc theo branch được chọn
                $childIds  = Category::whereIn('parent_id', $branchIds)->pluck('id');
                $filterIds = collect($branchIds)->merge($childIds)->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
            }

            if ($displayMode !== 'by_region') {
                $orderBy = $data['order_by'] ?? 'latest';
                match ($orderBy) {
                    'price_asc'  => $query->orderBy('price'),
                    'price_desc' => $query->orderByDesc('price'),
                    default      => $query->latest(),
                };
            }
        }

        // Block "Theo khu vực": ưu tiên phòng đánh giá cao và nhiều đơn đặt (đã thanh toán/đặt
        // cọc) của khu vực đó lên trước, bất kể order_by cấu hình gì — thay cho ordering mặc định.
        if ($displayMode === 'by_region') {
            $query->withCount(['orderItems as bookings_count' => function ($q) {
                $q->whereHas('order', fn ($oq) => $oq->whereIn('status', ['paid', 'deposit']));
            }])
                ->orderByDesc('rating_score')
                ->orderByDesc('bookings_count');
        }

        if ($tabRoomTypeId !== null) {
            $query->where('room_type_id', $tabRoomTypeId);
        }

        $branchLookup = $this->globalBranchLookup();

        return $query
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType', 'categories'])
            ->get()
            ->map(function ($room) use ($wishlistedIds, $branchLookup) {
                $status = $wishlistedIds === null ? null : \in_array($room->id, $wishlistedIds);
                $card   = $this->mapRoom($room, $status);
                $card['branch'] = $this->resolveBranch($room, $branchLookup['cats'], $branchLookup['childMap']);

                return $card;
            })
            ->toArray();
    }

    // ─── Promotion list ──────────────────────────────────────────────────────

    private function buildPromotionList(array $data, int $index, ?array $wishlistedIds, ?Province $province): array
    {
        $icon = $data['icon'] ?? null;
        $base = [
            'type'         => 'promotion_list',
            'id'           => $index + 1,
            'sort_order'   => $index + 1,
            'icon_url'     => $icon ? Storage::disk('public')->url($icon) : null,
            'title'        => $data['title'] ?? null,
            'view_all_url' => $province ? '/s/' . $province->slug : null,
            'rooms'        => [],
        ];

        if ($province === null) {
            return $base;
        }

        $productIds = array_filter((array) ($data['product_ids'] ?? []));
        if (empty($productIds)) {
            return $base;
        }

        $provinceBranchIds = $province->branches()
            ->where('status', true)
            ->pluck('categorie_id')
            ->toArray();

        if (empty($provinceBranchIds)) {
            return $base;
        }

        $childIds  = Category::whereIn('parent_id', $provinceBranchIds)->pluck('id');
        $filterIds = collect($provinceBranchIds)->merge($childIds)->unique()->values();

        $branchLookup = $this->globalBranchLookup();

        $rooms = Product::whereIn('id', $productIds)
            ->where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds))
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType', 'categories'])
            ->get()
            ->map(function ($room) use ($wishlistedIds, $branchLookup) {
                $status = $wishlistedIds === null ? null : \in_array($room->id, $wishlistedIds);
                $card   = $this->mapRoom($room, $status);
                $card['branch'] = $this->resolveBranch($room, $branchLookup['cats'], $branchLookup['childMap']);

                return $card;
            })
            ->values()
            ->toArray();

        return array_merge($base, ['rooms' => $rooms]);
    }

    // ─── Suggestion list ─────────────────────────────────────────────────────

    private function buildSuggestionList(array $data, int $index, ?array $wishlistedIds, ?Province $province = null): array
    {
        $type = $data['type'] ?? 'room';

        $base = [
            'type'            => 'suggestion_list',
            'id'              => $index + 1,
            'sort_order'      => $index + 1,
            'suggestion_type' => $type,
            // Loại "Chi nhánh" → xem tất cả dẫn đến danh sách chi nhánh của khu vực (không phải phòng).
            'view_all_url'    => $province
                ? '/s/' . $province->slug . ($type === 'branch' ? '?view=branches' : '')
                : null,
        ];

        if ($province === null) {
            return array_merge($base, [
                'message' => 'Vui lòng chọn khu vực của bạn',
                'items'   => [],
            ]);
        }

        $items = $type === 'branch'
            ? $this->getSuggestionBranches($province)
            : $this->getSuggestionRooms($province, $wishlistedIds);

        return array_merge($base, ['items' => $items]);
    }

    private function getSuggestionBranches(Province $province): array
    {
        return $province->branches()
            ->where('status', true)
            ->whereHas('category', fn ($q) => $q->where('status', true))
            ->with('category')
            ->get()
            ->map(fn ($branch) => [
                'id'        => $branch->category->id,
                'name'      => $branch->category->name,
                'slug'      => $branch->category->slug,
                'image_url' => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
                'thumbnail' => $branch->category->thumbnail,
            ])
            ->values()
            ->toArray();
    }

    private function getSuggestionRooms(Province $province, ?array $wishlistedIds = null): array
    {
        $branchCategoryIds = $province->branches()
            ->where('status', true)
            ->pluck('categorie_id')
            ->toArray();

        if (empty($branchCategoryIds)) {
            return [];
        }

        $childIds  = Category::whereIn('parent_id', $branchCategoryIds)->pluck('id');
        $filterIds = collect($branchCategoryIds)->merge($childIds)->unique()->values();

        $branchLookup = $this->globalBranchLookup();

        return Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds))
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType', 'categories'])
            ->get()
            ->map(function ($room) use ($wishlistedIds, $branchLookup) {
                $status = $wishlistedIds === null ? null : \in_array($room->id, $wishlistedIds);
                $card   = $this->mapRoom($room, $status);
                $card['branch'] = $this->resolveBranch($room, $branchLookup['cats'], $branchLookup['childMap']);

                $card['image_url'] = $card['thumbnail_url'];
                unset($card['thumbnail_url']);

                return $card;
            })
            ->values()
            ->toArray();
    }
}
