<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Settings\GeneralSettings;
use Livewire\Component;
use Modules\SettingCompany\Entities\Business;
use Modules\ThemeSetting\App\Models\ThemeSection;
use Modules\BladeThemeV1\Enums\FooterSection;
use Modules\BladeThemeV1\Support\ThemeCache;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;

class Footer extends Component
{
    use HandleCalculateTrait, HandleSectionCfgTrait;

    // Footer "style 2" (giao diện tĩnh, xem livewire/footer-v2.blade.php) đang là mặc định — footer
    // cấu hình động qua ThemeSection (bên dưới) tạm ẩn, không xoá, đổi lại thành true để bật lại.
    private const USE_LEGACY_FOOTER = false;

    public array $footerConfig = [];
    public array $footerBottomConfig = [];
    public $smColumns, $mdColumns, $lgColumns;
    public string $logo;
    private ThemeSection $section;
    private GeneralSettings $generalSettings;

    public function mount()
    {
        // Footer style 2 (mặc định) không cần cấu hình ThemeSection — bỏ qua toàn bộ phần dựng
        // $footerConfig/$footerBottomConfig bên dưới (giữ nguyên code để bật lại legacy footer
        // sau này chỉ bằng cách đổi USE_LEGACY_FOOTER = true).
        if (! self::USE_LEGACY_FOOTER) {
            return;
        }

        $this->generalSettings = ThemeCache::generalSettings();

        $this->section = $this->getFooterConfig();
        $footerMainConfig = $this->getChildSectionConfigs(FooterSection::FOOTER_MAIN->value);
        $footerBottomConfig = $this->getChildSectionConfigs(FooterSection::FOOTER_BOTTOM->value);

        $this->calculateColumns($footerMainConfig['footer_column']);
        $this->logo = $this->generalSettings->brand_logo;

        $this->footerConfig = [
            'newsletter' => [
                'is_visible' => $footerMainConfig['newsletter'],
                'heading' => $footerMainConfig['heading'],
                'description' => $footerMainConfig['description'],
                'form' => $footerMainConfig['form'],
            ],
            'content' => $this->formatFooterContent($footerMainConfig['footer_content']),
            'styling' => [
                'columns' => [
                    'sm' => $this->smColumns,
                    'md' => $this->mdColumns,
                    'lg' => $this->lgColumns,
                ],
                'backgroundColor' => $footerMainConfig['background_color'],
                'titleColor' => $footerMainConfig['title_color'] ?? 'var(--color-primary)',
                'textColor' => $footerMainConfig['base_color'],
            ]
        ];
        $this->footerBottomConfig = [
            'base_color' => $footerBottomConfig['base_color'],
            'background_color' => $footerBottomConfig['background_color'],
            'copyright' => $footerBottomConfig['copyright'],
            'bottom_menu' => (function () use ($footerBottomConfig) {
                $menuId = $footerBottomConfig['bottom_menu'];
                $menuItems = $menuId ? ThemeCache::menuById((int) $menuId) : null;
                return $this->formatMenuItems($menuItems);
            })()
        ];
    }

    private function formatFooterContent(array $footerContent): array
    {
        $formattedContent = [];

        foreach ($footerContent as $column) {
            $formattedColumn = [
                'title' => $column['title'],
                'content' => []
            ];

            foreach ($column['rows'] as $row) {
                $contentItem = [
                    'type' => $row['content_type'],
                    'data' => null
                ];

                switch ($row['content_type']) {
                    case 'text':
                        $contentItem['data'] = $row['text_content'];
                        break;

                    case 'business':
                        $contentItem['data'] = Business::find($row['business_id'])?->toArray();
                        break;

                    case 'image':
                        $contentItem['data'] =  $row['image_content'];
                        break;

                    case 'menu':
                        if ($row['menu_id']) {
                            $menuItems = ThemeCache::menuById((int) $row['menu_id']);
                            if ($menuItems) {
                                $contentItem['data'] = $this->formatMenuItems($menuItems);
                            }
                        }
                        break;

                    case 'social_media':
                        $contentItem['data'] = array_map(function($social) {
                            return [
                                'platform' => $social['platform'],
                                'url' => $social['url'],
                            ];
                        }, $row['social_media']);
                        break;

                    case 'iframe':
                        $contentItem['data'] = $row['iframe_content'];
                        break;

                    case 'google_map':
                        $contentItem['data'] = $row['google_map'];
                        break;
                }

                $formattedColumn['content'][] = $contentItem;
            }

            $formattedContent[] = $formattedColumn;
        }

        return $formattedContent;
    }

    public function calculateColumns(int $columnsCount)
    {
        $columns = $this->calculateColumnsTrait(null, $columnsCount);
        $this->smColumns = $columns['sm'];
        $this->mdColumns = $columns['md'];
        $this->lgColumns = $columns['lg'];
    }

    private function getFooterConfig()
    {
        return ThemeSection::where('name', 'footer')
            ->with(['children'])
            ->first();
    }

    private function formatMenuItems($menuItems, $parentId = null): array
    {
        $items = [];

        foreach ($menuItems as $item) {
            if ($item->parent_id === $parentId && $item->target !== '_parent') {
                $children = $this->formatMenuItems($menuItems, $item->id);

                $items[] = [
                    'title' => $item->title,
                    'url' => $item->url,
                    'target' => $item->target,
                    'order' => $item->order,
                    'children' => $children,
                ];
            }
        }

        return $items;
    }

    public function render()
    {
        if (self::USE_LEGACY_FOOTER) {
            return view('bladethemev1::livewire.footer', [
                'footerConfig' => $this->footerConfig
            ]);
        }

        return view('bladethemev1::livewire.footer-v2', [
            'business' => Business::first(),
        ]);
    }
}
