<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Settings\GeneralSettings;
use Illuminate\View\View;
use JetBrains\PhpStorm\NoReturn;
use Livewire\Component;
use Modules\Menu\Entities\Menu;
use Modules\ThemeSetting\App\Models\ThemeSection;
use Modules\BladeThemeV1\Enums\HeaderSection;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;

/**
 * @property $children
 */
class Header extends Component
{
    use HandleColorTrait, HandleSectionCfgTrait;

    public array $topbarConfig = [];
    public array $headerMainConfig = [];
    public array $logoConfig = [];
    public array $navConfig = [];
    public array $actionConfig = [];
    public array $cartConfig = [];
    public string $logo;
    public string $logoLightVersion;
    public ?Menu $menu;

    private ThemeSection $section;
    private GeneralSettings $generalSettings;

    #[NoReturn] public function mount(): void
    {
        $this->generalSettings = new GeneralSettings();

        $this->section = $this->getHeaderConfigs();
        $this->topbarConfig = $this->getChildSectionConfigs(HeaderSection::TOP_BAR->value);
        $this->headerMainConfig = $this->getChildSectionConfigs(HeaderSection::HEADER_MAIN->value);
        $this->logoConfig = $this->getChildSectionConfigs(HeaderSection::LOGO->value);
        $this->navConfig = $this->getChildSectionConfigs(HeaderSection::NAVIGATION_BAR->value);
        $this->logo = $this->generalSettings->brand_logo;
        $this->logoLightVersion = $this->generalSettings->brand_logo_light_version;
        $this->actionConfig = $this->getChildSectionConfigs(HeaderSection::ACTIONS->value);
        $this->cartConfig = $this->getChildSectionConfigs(HeaderSection::CART->value);
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

    private function getHeaderConfigs()
    {
        return ThemeSection::where('name', 'header')
            ->with(['children'])
            ->first();
    }

    public function render(): View
    {
        $headerData = new \Modules\BladeThemeV1\Types\HeaderData(
            $this->logo,
            $this->logoLightVersion,
            $this->logoConfig,
            $this->headerMainConfig,
            $this->navConfig,
            $this->actionConfig,
            $this->section->visible ?? false
        );
        return view('bladethemev1::livewire.header', [
            'data' => $headerData,
            'menu' => $this->menu,
            'topbarConfig' => $this->topbarConfig
        ]);
    }
}
