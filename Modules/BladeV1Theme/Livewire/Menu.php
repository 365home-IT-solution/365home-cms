<?php

namespace Modules\BladeThemeV1\Livewire;

use Filament\Facades\Filament;
use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\Menu\Entities\Menu as MenuEntityThemeKhoGa;
use Modules\Header\Entities\Header;

class Menu extends Component
{
    use HandleColorTrait;

    public $headerConfig;
    public $logo;

    public function mount() {
        $this->headerConfig = $this->getHeaderConfigs();
        $this->logo = Filament::getBrandLogo();
    }

    private function getHeaderConfigs(): array {
        $header = Header::with('contacts')->where('status', 1)->first();

        if(!$header) {
            return [];
        }

        return [
            'name' => $header->name,
            'logo_size' => $header->logo_size,
            'background_color' => $header->background_color,
            'contacts' => $header->contacts?->map(function ($contact){
                return [
                    'icon' => $contact->icon ?? 'null',
                    'name' => $contact->name,
                    'value' => $contact->value
                ];
            })->toArray()
        ];
    }

    public function render()
    {
        $menu = MenuEntityThemeKhoGa::query()
            ->with([
                'menuItems' => function ($query) {
                    $query->whereNull('parent_id')
                        ->where('url', '!=', '/')
                        ->orderBy('order')
                        ->with(['children' => function ($query) {
                            $query->where('url', '!=', '/')->orderBy('order')
                                ->with(['children' => function ($query) {
                                    $query->where('url', '!=', '/')->orderBy('order');
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
        $header = Header::where('status', 1)->first();

        if(!$header) {
            return '<div></div>';
        }

        return view('bladethemev1::livewire.menu', [
            'menu' => $menu,
            'header' => $header
        ]);
    }
}
