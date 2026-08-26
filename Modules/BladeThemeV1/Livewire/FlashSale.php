<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class FlashSale extends Component
{
    public array $criticalHome = [];

    public function mount(array $criticalHome = []): void
    {
        $this->criticalHome = $criticalHome;
    }

    public function render()
    {
        return view('bladethemev1::livewire.flash-sale');
    }
}
