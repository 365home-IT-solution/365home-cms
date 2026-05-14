<?php

namespace Modules\BladeThemeV1\Livewire;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

use Livewire\Component;

class Stat extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    public function getStats(): array
    {

        $stats = $this->getConfig('stats');
        if (!$stats) {
            return [];
        }

        return collect($stats)->map(function ($stat) {
            return [
                'count_number' => $stat['count_number'] ?? null,
                'name' => $stat['name'] ?? null,
                'description' => $stat['description'] ?? null,
            ];
        })->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.stat',[
            'stats' => $this->getStats(),
            'background_stat' => $this->getConfig('background_stat', '#f3f4f6'),
            'color_stat' => $this->getConfig('color_stat'),
        ]);
    }
}
