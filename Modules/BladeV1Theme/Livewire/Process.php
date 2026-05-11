<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Process\Entities\Step;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Process extends Component
{
    use HandleConfigTrait;
    public array $colors = [
        'bg-yellow-100',
        'bg-green-100',
        'bg-blue-100',
        'bg-red-100',
        'bg-purple-100',
    ];

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    private function fetchData(): ?array
    {
        $process_id = $this->getConfig('process');

        if(!$process_id) {
            return null;
        }

        return Step::where('process_id', $process_id)
            ->orderBy('order')
            ->get()
            ->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.process', [
            'steps' => $this->fetchData(),
            'process_style' => $this->getConfig('process_style', 'flow_diagram'),
            'process_title_color' => $this->getConfig('process_title_color'),
            'process_description_color' => $this->getConfig('process_description_color')
        ]);
    }
}
