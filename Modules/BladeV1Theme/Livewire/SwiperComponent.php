<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class SwiperComponent extends Component
{
    use HandleConfigTrait;

    public $images = [];
    public string $uniqueId = '';

    public function mount($config)
    {
        $this->setConfig($config);

        // Tạo uniqueId nếu chưa có
        $this->uniqueId = 'gallery-' . uniqid();

        if (!empty($config['component']['images'])) {
            $decoded = json_decode($config['component']['images'], true);

            if (is_array($decoded)) {
                $this->images = array_values($decoded);
            }
        }
    }


    public function render()
    {
        return view('bladethemev1::livewire.swiper-component');
    }
}