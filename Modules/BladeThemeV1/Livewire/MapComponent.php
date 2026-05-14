<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class MapComponent extends Component
{
    use HandleConfigTrait;

    public $latitude;
    public $longitude;

    public function mount($config)
    {
        // Đặt config từ dữ liệu đầu vào
        $this->setConfig($config);

        // Lấy latitude và longitude từ config, nếu không có thì dùng giá trị mặc định
        $this->latitude = $this->getConfig('latitude', '10.0336');
        $this->longitude = $this->getConfig('longitude', '105.7463');
    }

    public function render()
    {
        return view('bladethemev1::livewire.map-component', [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude
        ]);
    }
}