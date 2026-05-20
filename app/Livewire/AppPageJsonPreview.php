<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Concerns\BuildsRoomCard;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\AppPage\App\Models\AppPage;
use Modules\Product\App\Models\Product;

class AppPageJsonPreview extends Component
{
    use BuildsRoomCard;

    public ?int $pageId = null;

    #[On('app-page-json-refresh')]
    public function refresh(): void {}

    public function render()
    {
        $json = null;
        $page = $this->pageId ? AppPage::find($this->pageId) : null;

        if ($page) {
            $sections = collect($page->content ?? [])
                ->filter(fn ($block) => ($block['type'] ?? '') === 'room_list')
                ->values()
                ->map(fn ($block, $index) => $this->buildSection($block['data'] ?? [], $index));

            $json = json_encode(
                [$page->slug => ['sections' => $sections]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return view('livewire.app-page-json-preview', compact('json', 'page'));
    }

    private function buildSection(array $data, int $index): array
    {
        return [
            'id'           => $index + 1,
            'title'        => $data['title'] ?? null,
            'subtitle'     => $data['subtitle'] ?? null,
            'view_all_url' => $data['view_all_url'] ?? null,
            'show_arrow'   => (bool) ($data['show_arrow'] ?? true),
            'layout'       => $data['layout'] ?? 'horizontal_scroll',
            'sort_order'   => $index + 1,
            'badge'        => ! empty($data['badge_label']) ? [
                'label'      => $data['badge_label'],
                'type'       => $data['badge_type'] ?? null,
                'bg_color'   => $data['badge_bg_color'] ?? '#FFFFFF',
                'text_color' => $data['badge_text_color'] ?? '#1F2937',
            ] : null,
            'rooms' => $this->getRooms($data),
        ];
    }

    private function getRooms(array $data): array
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
            ->with('roomTimeSlots.timeSlot')
            ->limit($limit)
            ->get()
            ->map(fn ($room) => $this->mapRoom($room))
            ->toArray();
    }
}
