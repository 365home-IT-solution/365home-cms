<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Http\Controllers;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\AppPage\App\Models\AppPage;
use Modules\Product\App\Models\Product;

class AppPageController extends Controller
{
    use BuildsRoomCard;

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
            ->filter(fn ($block) => ($block['type'] ?? '') === 'room_list')
            ->values()
            ->map(fn ($block, $index) => $this->buildSection($block['data'] ?? [], $index, $wishlistedIds));

        return response()->json([
            $slug => [
                'sections' => $sections,
            ],
        ]);
    }

    private function buildSection(array $data, int $index, ?array $wishlistedIds): array
    {
        return [
            'id'           => $index + 1,
            'title'        => $data['title'] ?? null,
            'subtitle'     => $data['subtitle'] ?? null,
            'view_all_url' => $data['view_all_url'] ?? null,
            'show_arrow'   => (bool) ($data['show_arrow'] ?? true),
            'layout'       => $data['layout'] ?? 'horizontal_scroll',
            'sort_order'   => $index + 1,
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
            ->with(['roomTimeSlots.timeSlot', 'mainImage'])
            ->limit($limit)
            ->get()
            ->map(function ($room) use ($wishlistedIds) {
                $status = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);

                return $this->mapRoom($room, $status);
            })
            ->toArray();
    }
}
