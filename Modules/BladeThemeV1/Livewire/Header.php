<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\ProvinceBranch;
use App\Settings\GeneralSettings;
use Illuminate\Support\Str;
use Illuminate\View\View;
use JetBrains\PhpStorm\NoReturn;
use Livewire\Component;
use Modules\Menu\Entities\Menu;
use Modules\ThemeSetting\App\Models\ThemeSection;
use Modules\BladeThemeV1\Enums\HeaderSection;
use Modules\BladeThemeV1\Support\ThemeCache;
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
    public bool $authHeaderEnabled = false;
    public string $logo;
    public string $logoLightVersion;
    public ?Menu $menu;

    private ThemeSection $section;
    private GeneralSettings $generalSettings;

    #[NoReturn] public function mount(): void
    {
        $this->generalSettings = ThemeCache::generalSettings();
        $this->authHeaderEnabled = (bool) ($this->generalSettings->auth_header['enabled'] ?? false);

        $this->section = $this->getHeaderConfigs();
        $this->topbarConfig = $this->getChildSectionConfigs(HeaderSection::TOP_BAR->value);
        $this->headerMainConfig = $this->getChildSectionConfigs(HeaderSection::HEADER_MAIN->value);
        $this->logoConfig = $this->getChildSectionConfigs(HeaderSection::LOGO->value);
        $this->navConfig = $this->getChildSectionConfigs(HeaderSection::NAVIGATION_BAR->value);
        $this->logo = $this->generalSettings->brand_logo;
        $this->logoLightVersion = $this->generalSettings->brand_logo_light_version;
        $this->actionConfig = $this->getChildSectionConfigs(HeaderSection::ACTIONS->value);
        $this->cartConfig = $this->getChildSectionConfigs(HeaderSection::CART->value);
        $this->menu = ThemeCache::menuForHeader();

        $this->applyBranchBookingUrls();
    }

    private function applyBranchBookingUrls(): void
    {
        if (!$this->menu || $this->menu->menuItems->isEmpty()) {
            return;
        }

        $branchMenu = $this->menu->menuItems->first(function ($item) {
            return $this->normalizeMenuText($item->title) === '365home'
                || trim((string) $item->url, '/') === '365home';
        });

        if (!$branchMenu || $branchMenu->children->isEmpty()) {
            return;
        }

        $branches = ProvinceBranch::query()
            ->where('status', true)
            ->whereNotNull('categorie_id')
            ->with('category:id,name,slug')
            ->get()
            ->filter(fn ($branch) => $branch->category);

        foreach ($branchMenu->children as $menuItem) {
            $branch = $this->resolveBranchForMenuItem($menuItem->title, $branches);

            if ($branch) {
                $menuItem->setAttribute('branch_booking_url', route('branch.booking', [
                    'slug' => $branch->category->slug,
                ]));
            }
        }
    }

    private function resolveBranchForMenuItem(string $title, $branches): ?ProvinceBranch
    {
        $menuKey = $this->normalizeMenuText($title);
        $firstToken = Str::of($menuKey)->explode(' ')->filter()->first();

        if (!$firstToken) {
            return null;
        }

        return $branches->first(function ($branch) use ($firstToken) {
            $branchKey = $this->normalizeMenuText($branch->category->name);

            return Str::startsWith($branchKey, $firstToken . ' ')
                || $branchKey === $firstToken
                || Str::contains($branchKey, ' ' . $firstToken . ' ');
        });
    }

    private function normalizeMenuText(?string $value): string
    {
        $value = Str::ascii((string) $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
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
