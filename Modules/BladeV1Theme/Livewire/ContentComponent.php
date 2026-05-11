<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class ContentComponent extends Component
{
    use HandleConfigTrait;

    public $content;

    public function mount($config)
    {
        // Lưu nội dung từ config vào thuộc tính $content
        $this->content = $config['component']['content-component'] ?? '';
        $this->setConfig($config);
    }

    public function render()
    {
        return view('bladethemev1::livewire.content-component');
    }
}