<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Banner extends Component
{
    use HandleConfigTrait;

    public function mount(array $config): void
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
    }

    public function render()
    {
        return view('bladethemev1::livewire.banner', [
            'banners' => $this->transformBannerData($this->getConfig('content')),
            'contact_group' => $this->transfromContactGroupData($this->getConfig('contact_group')),
            'show_pagination' => $this->getConfig('show_pagination'),
            'show_navigation' => $this->getConfig('show_navigation'),
            'content_alignment' => $this->getConfig('content_alignment', 'left'),
            'height_image' => $this->getConfig('height_image', 576),
            'width_swiper' => $this->getConfig('width_swiper', 100),
            'height_swiper' => $this->getConfig('height_swiper'),
            'layout' => $this->getConfig('layout', 'overlay'),
            'autoplay_speed' => $this->getConfig('autoplay_speed')
        ]);
    }
}
