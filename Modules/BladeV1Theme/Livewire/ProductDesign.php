<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class ProductDesign extends Component
{
    public $config;

    public function mount($config)
    {
        $this->config = $config;
    }

    public function render()
    {
        return view('bladethemev1::livewire.product-design');
    }
}