<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Branch extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
    }

    protected function getBranch(): array
    {
        $branch = $this->getConfig('branch');

        if (!$branch) {
            return [];
        }

        return collect($branch)->map(function ($item, $key) {
            return [
                'region_id' => $key,
                'region_name' => $item['region_name'],
                'region_code' => $item['region_code'],
                'type' => $item['type'],
                'branches' => collect($item['branches'])->map(function ($branch, $branchId) {
                    return [
                        'id' => $branchId,
                        'name' => $branch['name'],
                        'province' => $branch['province'],
                        'address' => trim($branch['address']),
                        'phone' => $branch['phone'],
                        'fax' => $branch['fax'],
                        'color' => $branch['color'],
                        'is_main' => $branch['is_main']
                    ];
                })->values()->toArray()
            ];
        })->values()->toArray();
    }

    public function render()
    {
        return view('bladethemev1::livewire.branch', [
            'branches' => $this->getbranch(),
        ]);
    }
}