<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class BottomSidebar extends Component
{
    public $activeUrl;

    public function __construct()
    {
        $this->activeUrl = request()->getPathInfo();
    }

    public function render()
    {
        return view('bladethemev1::livewire.bottom-sidebar');
    }
}
