<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\View\View;
use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Album extends Component
{
    use HandleConfigTrait;

    public function mount($config): void
    {
        $this->setConfig($config);
        $this->uniqueId = $this->generateUniqueId($this->getConfig('name'));
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.album', [
            'albums' => $this->transformAlbumsData($this->getConfig('albums')),
            'autoplay_speed' => $this->getConfig('autoplay_speed'),
            'layout' => $this->getConfig('layout', 'grid'),
            'column' => $this->getConfig('column', 4),
            'show_pagination' => $this->getConfig('show_pagination'),
            'show_navigation' => $this->getConfig('show_navigation')
        ]);
    }
}
