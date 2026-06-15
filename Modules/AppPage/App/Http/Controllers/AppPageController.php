<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Http\Controllers;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\AppPage;
use Modules\AppPage\App\Models\Banner;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class AppPageController extends Controller
{
    use BuildsRoomCard;

    private const SUPPORTED_BLOCKS = ['room_list', 'banner'];

    public function show(string $slug): JsonResponse
    {
        $page = AppPage::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $wishlistedIds = auth()->check()
            ? Wishlist::where('user_id', auth()->id())->pluck('product_id')->toArray()
            : null;

        $sections = collect($page->content ?? [])
            ->filter(fn ($block) => in_array($block['type'] ?? '', self::SUPPORTED_BLOCKS))
            ->values()
            ->map(fn ($block, $index) => $this->buildBlock($block, $index, $wishlistedIds));

        return response()->json([
            $slug => [
                'sections' => $sections,
            ],
        ]);
    }

    private function buildBlock(array $block, int $index, ?array $wishlistedIds): array
    {
        $type = $block['type'];
        $data = $block['data'] ?? [];

        return match ($type) {
            'banner'    => $this->buildBanner($data, $index),
            'room_list' => $this->buildRoomList($data, $index, $wishlistedIds),
            default     => [],
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
                'image_url'   => $banner->image ? Storage::disk($banner->disk ?? 'public')->url($banner->image) : null,
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

    private function buildRoomList(array $data, int $index, ?array $wishlistedIds): array
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
            'badge'        => $this->buildSectionBadge($data),
            'rooms'        => $this->getRooms($data, $wishlistedIds),
        ];
    }

    private function buildSectionBadge(array $data): ?array
    {
        if (empty($data['badge_label'])) {
            return null;
        }

        return [
            'label'      => $data['badge_label'],
            'type'       => $data['badge_type'] ?? null,
            'bg_color'   => $data['badge_bg_color'] ?? '#FFFFFF',
            'text_color' => $data['badge_text_color'] ?? '#1F2937',
        ];
    }

    private function getRooms(array $data, ?array $wishlistedIds): array
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
            if (! empty($branchIds)) {
                $childIds  = Category::whereIn('parent_id', $branchIds)->pluck('id');
                $filterIds = collect($branchIds)->merge($childIds)->unique()->values();
                $query->whereHas('categories', fn ($cq) => $cq->whereIn('category_id', $filterIds));
            }

            if (! empty($data['room_type_id'])) {
                $query->where('room_type_id', $data['room_type_id']);
            }

            $orderBy = $data['order_by'] ?? 'latest';
            match ($orderBy) {
                'price_asc'  => $query->orderBy('price'),
                'price_desc' => $query->orderByDesc('price'),
                default      => $query->latest(),
            };
        }

        $limit = max(1, (int) ($data['limit'] ?? 10));

        return $query
            ->with(['roomTimeSlots.timeSlot', 'mainImage', 'roomType'])
            ->limit($limit)
            ->get()
            ->map(function ($room) use ($wishlistedIds) {
                $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);

                return $this->mapRoom($room, $status);
            })
            ->toArray();
    }
}
