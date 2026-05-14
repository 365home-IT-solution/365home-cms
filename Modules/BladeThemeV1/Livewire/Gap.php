<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Gap extends Component
{

    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
    }

    public function render()
    {
        $padding = $this->getConfig('padding', 0);
        return view('bladethemev1::livewire.gap', [
            'padding' => $padding,
        ]);
    }
}
