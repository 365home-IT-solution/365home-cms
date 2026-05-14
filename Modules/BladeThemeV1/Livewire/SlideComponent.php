<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class SlideComponent extends Component
{
    use HandleConfigTrait;

    public string $style = 'default';
    public string $image_event_slide = '';

    public function mount($config)
    {
        $this->setConfig($config);
        $this->style = $config['component']['style'] ?? 'default';
        $this->uniqueId = $this->generateUniqueId($this->style);
        $this->image_event_slide = $this->getConfig('image_event_slide', '');
    }
    public function render()
    {
        return view('bladethemev1::livewire.slide-component',[
            'config' => $this->config,
            'settings' => $this->getConfig('setting_slide'),
            'image_event_slide' => $this->image_event_slide,
        ]);
    }
}
