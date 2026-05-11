<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class SwiperLogo extends Component
{
    use HandleConfigTrait;

    public function mount($config)
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
    }

    public function render()
    {
        return view('bladethemev1::livewire.swiper-logo', [
            'logos' => $this->transformAlbumsData($this->getConfig('logos')),
            'autoplay_speed' => $this->getConfig('autoplay_speed'),
            'layout' => $this->getConfig('layout', 'slide'),
            'column' => $this->getConfig('column', 4),
            'space_between' => $this->getConfig('space_between', 20),
            'show_pagination' => $this->getConfig('show_pagination'),
            'show_navigation' => $this->getConfig('show_navigation')
        ]);
    }
}
