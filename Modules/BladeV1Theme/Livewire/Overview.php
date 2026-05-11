<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Overview extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    protected function getOverview(): array
    {
        $overview = $this->getConfig('overview');

        if (!$overview) {
            return [];
        }

        return collect($overview)->map(function ($item, $key) {
            return [
                'id' => $key,
                'system_type' => $item['system_type'] ?? null,
                'sections' => collect($item['sections'] ?? [])->map(function ($section, $sectionKey) {
                    return [
                        'id' => $sectionKey,
                        'title' => $section['title'] ?? null,
                        'value' => $section['value'] ?? null,
                        'subtitle' => $section['subtitle'] ?? null,
                        'unit' => $section['unit'] ?? null,
                        'highlight_color' => $section['highlight_color'] ?? null,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.overview', [
            'overviews' => $this->getOverview(),
            'title' => $this->getConfig('title'),
        ]);
    }
}