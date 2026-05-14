<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class PerformanceResults extends Component
{
    use HandleConfigTrait;

    public $metrics = [];

    public function mount($config)
    {
        // Lấy dữ liệu JSON đã lưu
        $performanceRaw = $config['component']['performance-results'] ?? '{}';
        $performanceData = json_decode($performanceRaw, true);

        // Chuyển thành mảng phù hợp để render
        $this->metrics = collect($performanceData)->map(function ($item) {
            return [
                'value' => $item['value'],
                'unit' => $item['unit'],
                'label' => $item['label'],
            ];
        })->values()->all(); // bỏ key UUID nếu không cần
    }

    public function render()
    {
        return view('bladethemev1::livewire.performance-results');
    }
}

