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

class HomeController extends Controller
{
    use BuildsRoomCard;

    // tab_id → DB filter
    private const TAB_MAP = [
        '0' => ['type' => 'unit', 'value' => 'per_hour'],
        '1' => ['type' => 'unit', 'value' => 'per_day'],
        '2' => ['type' => 'unit', 'value' => 'per_night'],
        '3' => ['type' => 'tag',  'value' => 'self_check_in'],
        '4' => ['type' => 'tag',  'value' => 'couple'],
        '5' => ['type' => 'tag',  'value' => 'chill'],
        '6' => ['type' => 'tag',  'value' => 'family'],
        '7' => ['type' => 'tag',  'value' => 'birthday'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $page = AppPage::where('slug', 'home')
            ->where('is_active', true)
            ->first();

        if (! $page) {
            return response()->json(['message' => 'Home page not found.'], 404);
        }

        $tab = $request->query('tab');
        $tabFilter = isset($tab) ? (self::TAB_MAP[(string) $tab] ?? null) : null;

        $wishlistedIds = auth()->check()
            ? Wishlist::where('user_id', auth()->id())->pluck('product_id')->toArray()
            : null;

        $sections = collect($page->content ?? [])
            ->filter(fn ($block) => in_array($block['type'] ?? '', ['banner', 'room_list']))
            ->values()
            ->map(fn ($block, $index) => $this->buildBlock($block, $index, $wishlistedIds, $tabFilter))
            ->filter()
            ->values();

        return response()->json([
            'home' => [
                'sections' => $sections,
            ],
        ]);
    }

    private function buildBlock(array $block, int $index, ?array $wishlistedIds, ?array $tabFilter): ?array
    {
        return match ($block['type']) {
            'banner'    => $this->buildBanner($block['data'] ?? [], $index),
            'room_list' => $this->buildRoomList($block['data'] ?? [], $index, $wishlistedIds, $tabFilter),
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

    private function buildRoomList(array $data, int $index, ?array $wishlistedIds, ?array $tabFilter): array
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
            'rooms'        => $this->getRooms($data, $wishlistedIds, $tabFilter),
        ];
    }

    private function getRooms(array $data, ?array $wishlistedIds, ?array $tabFilter): array
    {
        $productIds = $data['product_ids'] ?? [];

        if (! empty($productIds)) {
            $query = Product::whereIn('id', $productIds)
                ->where('is_activated', true)
                ->where('is_in_stock', true);
        } else {
            $query = Product::where('is_activated', true)
                ->where('is_in_stock', true);

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

        // Apply tab filter
        if ($tabFilter) {
            if ($tabFilter['type'] === 'unit') {
                $query->where('price_unit', $tabFilter['value']);
            } elseif ($tabFilter['type'] === 'tag') {
                $query->withAnyTags([$tabFilter['value']]);
            }
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
