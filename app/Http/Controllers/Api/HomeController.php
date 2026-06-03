<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\AppPage;
use Modules\AppPage\App\Models\Banner;
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

        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url'])
            ->toArray();

        $wishlistedIds = auth()->check()
            ? Wishlist::where('user_id', auth()->id())->pluck('product_id')->toArray()
            : null;

        $sections = collect($page->content ?? [])
            ->filter(fn ($block) => in_array($block['type'] ?? '', ['banner', 'room_list']))
            ->values()
            ->map(fn ($block, $index) => $this->buildBlock($block, $index, $wishlistedIds, $tabRoomTypeId))
            ->filter()
            ->values();

        return response()->json([
            'home' => [
                'room_types' => $roomTypes,
                'sections'   => $sections,
            ],
        ]);
    }

    private function buildBlock(array $block, int $index, ?array $wishlistedIds, ?int $tabRoomTypeId): ?array
    {
        return match ($block['type']) {
            'banner'    => $this->buildBanner($block['data'] ?? [], $index),
            'room_list' => $this->buildRoomList($block['data'] ?? [], $index, $wishlistedIds, $tabRoomTypeId),
            default     => null,
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

    // ─── Room list ───────────────────────────────────────────────────────────

    private function buildRoomList(array $data, int $index, ?array $wishlistedIds, ?int $tabRoomTypeId): array
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
            'rooms'        => $this->getRooms($data, $wishlistedIds, $tabRoomTypeId),
        ];
    }

    private function getRooms(array $data, ?array $wishlistedIds, ?int $tabRoomTypeId): array
    {
        $productIds = $data['product_ids'] ?? [];

        if (! empty($productIds)) {
            $query = Product::whereIn('id', $productIds)
                ->where('is_activated', true)
                ->where('is_in_stock', true);
        } else {
            $query = Product::where('is_activated', true)
                ->where('is_in_stock', true);

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
