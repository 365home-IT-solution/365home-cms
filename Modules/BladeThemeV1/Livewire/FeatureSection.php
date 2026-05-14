<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class FeatureSection extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    protected function getServices(): array
    {
        $services = $this->getConfig('services');
        if (!$services) {
            return [];
        }

        return collect($services)->map(function ($service) {
            return [
                'icon' => array_values($service['icon'])[0] ?? null, // Lấy giá trị đầu tiên của mảng icon
                'name' => $service['name'] ?? null,
                'description' => $service['description'] ?? null,
            ];
        })->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.feature-section', [
            'services' => $this->getServices()
        ]);
    }
}
