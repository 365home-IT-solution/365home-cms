<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
class Test extends Component
{
    public function mount($config)
    {
        dd($config);
        $this->setConfig($config);
    }

    public function render()
    {
        return view('bladethemev1::livewire.test');
    }
}
