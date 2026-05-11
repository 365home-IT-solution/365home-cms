<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class ContentSection extends Component
{
    use HandleConfigTrait;
    use HandleColorTrait;

    public function mount($config)
    {
        $this->setConfig($config);
    }

    public function render()
    {
        $primaryColor = $this->getFilamentPrimaryColor();
        $color_theme = $this->getConfig('color_theme', $primaryColor);
        $color_theme_dark = $this->darkenColor($color_theme, 50);
        $color_theme_light = $this->lightenColor($color_theme, 100);

        return view('bladethemev1::livewire.content-section', [
            'icon' => $this->getConfig('icon'),
            'sub_title' => $this->getConfig('sub_title'),
            'title' => $this->getConfig('title'),
            'content' => $this->getConfig('content'),
            'button_cta' => $this->getConfig('button_cta'),
            'button_cta_link' => $this->getConfig('button_cta_link'),
            'button_cta_style' => $this->getConfig('button_cta_style', 'default'),
            'media_file' => $this->getConfig('media_file'),
            'media_file_background' => $this->getConfig('media_file_background', false),
            'border_radius' => $this->getConfig('border_radius', 24),
            'media_file_height' => $this->getConfig('media_file_height', 428),
            'content_alignment' => $this->getConfig('content_alignment', 'left'),
            'color_theme' => $color_theme,
            'color_theme_dark' => $color_theme_dark,
            'color_theme_light' => $color_theme_light
        ]);
    }
}
