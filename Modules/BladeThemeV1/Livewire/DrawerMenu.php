<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Menu\Entities\Menu;
use Modules\BladeThemeV1\Traits\HandleColorTrait;

class DrawerMenu extends Component
{
    use HandleColorTrait;

    public $menu;

    public function mount($menu = null)
    {
        if ($menu) {
            $this->menu = $menu;
        } else {
            $this->menu = Menu::query()
                ->with([
                    'menuItems' => function ($query) {
                        $query->whereNull('parent_id')
                            ->orderBy('order')
                            ->with(['children' => function ($query) {
                                $query->orderBy('order')
                                    ->with(['children' => function ($query) {
                                        $query->orderBy('order');
                                    }]);
                            }]);
                    },
                    'locations' => function ($query) {
                        $query->where('location', 'header');
                    }
                ])
                ->whereHas('locations', function ($query) {
                    $query->where('location', 'header');
                })
                ->where('is_visible', true)
                ->first();
        }
    }

    public function render()
    {
        return view('bladethemev1::livewire.drawer-menu', [
            'menu' => $this->menu,
        ]);
    }
}
