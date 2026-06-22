<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
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
    use BuildsRoomCard;

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

        $province = null;
        if ($request->query('province') !== null) {
            $province = Province::find((int) $request->query('province'));
        }

        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url'])
            ->toArray();

        $authUser      = auth('sanctum')->user();
        $wishlistedIds = $authUser
            ? $authUser->wishlists()->pluck('product_id')->toArray()
            : null;

        $blocks = collect($page->content ?? [])
            ->filter(function ($block) use ($province) {
                $type = $block['type'] ?? '';
                if (! in_array($type, ['banner', 'room_list', 'province_list'])) {
                    return false;
                }
                return ! ($type === 'province_list' && $province !== null);
            })
            ->values();

        if ($province !== null) {
            $blocks = collect([['type' => '__branch_list__', 'data' => []]])
                ->merge($blocks)
                ->values();
        }

        $sections = $blocks
            ->map(fn ($block, $index) => $this->buildBlock($block, $index, $wishlistedIds, $tabRoomTypeId, $province))
            ->filter()
            ->values();

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
            'province_list'   => $this->buildProvinceList($block['data'] ?? [], $index),
            '__branch_list__' => $province ? $this->buildBranchList($province, $index) : null,
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

    // ─── Province list ───────────────────────────────────────────────────────

    private function buildProvinceList(array $data, int $index): array
    {
        $provinceIds = collect($data['items'] ?? [])
            ->pluck('province_id')
            ->filter()
            ->values()
            ->toArray();

        $provincesById = Province::whereIn('id', $provinceIds)
            ->get()
            ->keyBy('id');

        $items = collect($provinceIds)
            ->map(fn ($id) => $provincesById->get($id))
            ->filter()
            ->map(fn (Province $province) => [
                'id'        => $province->id,
                'name'      => $province->name,
                'slug'      => $province->slug,
                'image_url' => $province->image
                    ? Storage::disk('public')->url($province->image)
                    : null,
            ])
            ->values()
            ->toArray();

        return [
            'type'       => 'province',
            'id'         => $index + 1,
            'sort_order' => $index + 1,
            'items'      => $items,
        ];
    }

    // ─── Branch list (khi đã chọn province) ─────────────────────────────────

    private function buildBranchList(Province $province, int $index): array
    {
        $branches = $province->branches()
            ->where('status', true)
            ->with('category')
            ->get()
            ->map(fn ($branch) => [
                'id'        => $branch->category->id,
                'name'      => $branch->category->name,
                'slug'      => $branch->category->slug,
                'image_url' => $branch->category->image
                    ? Storage::disk('public')->url($branch->category->image)
                    : null,
            ])
            ->values()
            ->toArray();

        return [
            'type'       => 'branch_list',
            'id'         => $index + 1,
            'sort_order' => $index + 1,
            'province'   => [
                'id'   => $province->id,
                'name' => $province->name,
                'slug' => $province->slug,
            ],
            'items' => $branches,
        ];
    }

    // ─── Room list ───────────────────────────────────────────────────────────

    private function buildRoomList(array $data, int $index, ?array $wishlistedIds, ?int $tabRoomTypeId, ?Province $province = null): array
    {
        return [
            'type'         => 'room_list',
            'id'           => $index + 1,
            'sort_order'   => $index + 1,
            'title'        => $data['title'] ?? null,
            'subtitle'     => $data['subtitle'] ?? null,
            'view_all_url' => $data['view_all_url'] ?? null,
            'show_arrow'   => (bool) ($data['show_arrow'] ?? true),
            'layout'       => $data['layout'] ?? 'horizontal_scroll',
            'rooms'        => $this->getRooms($data, $wishlistedIds, $tabRoomTypeId, $province),
        ];
    }

    private function getRooms(array $data, ?array $wishlistedIds, ?int $tabRoomTypeId, ?Province $province = null): array
    {
        $productIds = $data['product_ids'] ?? [];

        if (! empty($productIds)) {
            $query = Product::whereIn('id', $productIds)
                ->where('is_activated', true)
                ->where('is_in_stock', true);
        } else {
            $query = Product::where('is_activated', true)
                ->where('is_in_stock', true);

            $branchIds = array_filter((array) ($data['branch_ids'] ?? []));

            if ($province !== null) {
                $provinceBranchIds = $province->branches()
                    ->where('status', true)
                    ->pluck('categorie_id')
                    ->toArray();

                // Nếu block có cấu hình branch_ids, lấy phần giao với province
                $effectiveBranchIds = ! empty($branchIds)
                    ? array_values(array_intersect($branchIds, $provinceBranchIds))
                    : $provinceBranchIds;

                if (! empty($effectiveBranchIds)) {
                    $childIds  = Category::whereIn('parent_id', $effectiveBranchIds)->pluck('id');
                    $filterIds = collect($effectiveBranchIds)->merge($childIds)->unique()->values();
                    $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
                }
            } elseif (! empty($branchIds)) {
                $childIds  = Category::whereIn('parent_id', $branchIds)->pluck('id');
                $filterIds = collect($branchIds)->merge($childIds)->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
            }

            $orderBy = $data['order_by'] ?? 'latest';
            match ($orderBy) {
                'price_asc'  => $query->orderBy('price'),
                'price_desc' => $query->orderByDesc('price'),
                default      => $query->latest(),
            };
        }

        if ($tabRoomTypeId !== null) {
            $query->where('room_type_id', $tabRoomTypeId);
        }

        return $query
            ->with(['roomTimeSlots.timeSlot', 'media', 'roomType'])
            ->get()
            ->map(function ($room) use ($wishlistedIds) {
                $status = $wishlistedIds === null ? null : \in_array($room->id, $wishlistedIds);

                return $this->mapRoom($room, $status);
            })
            ->toArray();
    }
}
