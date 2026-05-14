<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
class Settings extends Component
{
    use HandleConfigTrait;

    public $settings;

    public function mount($config, $uniqueId, $settings)
    {
        $this->settings = $settings;
        $this->uniqueId = $uniqueId;
        $this->setConfig($config);
    }

    public function render()
    {
        return view('bladethemev1::livewire.settings', [
            'uniqueId' => $this->uniqueId,
        ]);
    }
}
