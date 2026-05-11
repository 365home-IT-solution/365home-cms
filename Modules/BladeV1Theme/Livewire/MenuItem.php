<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class MenuItem extends Component
{
    use HandleColorTrait;

    public $menuItem;
    public $depth;
    public $loop;
    public $navStyle;
    public $sizeClassess;
    public $navUppercase;

    public function mount(
        $menuItem,
        $depth,
        $loop,
        $navStyle = 'default',
        $navSize = 'md',
        $navUppercase = false,
    ) {
        $this->menuItem = $menuItem;
        $this->depth = $depth;
        $this->loop = $loop;
        $this->navStyle = $navStyle;

        $this->navUppercase = $navUppercase ? 'uppercase': 'lowercase';

        $this->sizeClassess = match($navSize) {
            'xs' => 'text-xs',
            'sm' => 'text-sm',
            'lg' => 'text-lg',
            'xl' => 'text-xl',
            default => 'text-md'
        };
    }

    public function render()
    {
        return view('bladethemev1::livewire.menu-item');
    }
}
