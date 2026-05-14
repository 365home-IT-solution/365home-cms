<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class ListComponent extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    protected function getListItems(): array
    {
        $items = $this->getConfig('images');

        if (!$items) {
            return [];
        }

        return collect($items)->map(function ($item, $key) {
            return [
                'id' => $key,
                'title' => $item['title'] ?? null,
                'image' => $this->getFirstImage($item['image'] ?? []),
                'description' => $item['desciption'] ?? null, // Lưu ý có lỗi chính tả "desciption"
            ];
        })->values()->toArray();
    }

    protected function getFirstImage($images): ?string
    {
        if (!$images || !is_array($images)) {
            return null;
        }

        $firstKey = array_key_first($images);
        return $images[$firstKey] ?? null;
    }

    public function render()
    {
        return view('bladethemev1::livewire.list-component', [
            'items' => $this->getListItems(),
            'title' => $this->getConfig('title'),
        ]);
    }
}