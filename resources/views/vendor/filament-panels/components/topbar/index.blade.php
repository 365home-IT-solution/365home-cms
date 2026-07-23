@props([
    'navigation',
])

{{--
    Override của vendor/filament/filament/resources/views/components/topbar/index.blade.php —
    CHỈ khác bản gốc ở phần dropdown nhóm điều hướng (mega menu nhiều cột bên dưới, đánh dấu
    "MEGA MENU"). Khi nâng cấp Filament, đối chiếu lại phần còn nguyên với file gốc, chỉ cần giữ
    nguyên đoạn mega menu.
--}}

<div
    {{
        $attributes->class([
            'fi-topbar sticky top-0 z-20 overflow-x-clip',
            'fi-topbar-with-navigation' => filament()->hasTopNavigation(),
        ])
    }}
>
    <nav
        class="flex h-16 items-center gap-x-4 bg-white px-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 md:px-6 lg:px-8"
    >
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_START) }}

        @if (filament()->hasNavigation())
            <x-filament::icon-button
                color="gray"
                icon="heroicon-o-bars-3"
                icon-alias="panels::topbar.open-sidebar-button"
                icon-size="lg"
                :label="__('filament-panels::layout.actions.sidebar.expand.label')"
                x-cloak
                x-data="{}"
                x-on:click="$store.sidebar.open()"
                x-show="! $store.sidebar.isOpen"
                @class([
                    'fi-topbar-open-sidebar-btn',
                    'lg:hidden' => (! filament()->isSidebarFullyCollapsibleOnDesktop()) || filament()->isSidebarCollapsibleOnDesktop(),
                ])
            />

            <x-filament::icon-button
                color="gray"
                icon="heroicon-o-x-mark"
                icon-alias="panels::topbar.close-sidebar-button"
                icon-size="lg"
                :label="__('filament-panels::layout.actions.sidebar.collapse.label')"
                x-cloak
                x-data="{}"
                x-on:click="$store.sidebar.close()"
                x-show="$store.sidebar.isOpen"
                class="fi-topbar-close-sidebar-btn lg:hidden"
            />
        @endif

        @if (filament()->hasTopNavigation() || (! filament()->hasNavigation()))
            <div class="me-6 hidden lg:flex">
                @if ($homeUrl = filament()->getHomeUrl())
                    <a {{ \Filament\Support\generate_href_html($homeUrl) }}>
                        <x-filament-panels::logo />
                    </a>
                @else
                    <x-filament-panels::logo />
                @endif
            </div>

            @if (filament()->hasTenancy() && filament()->hasTenantMenu())
                <x-filament-panels::tenant-menu class="hidden lg:block" />
            @endif

            @if (filament()->hasNavigation())
                <ul class="me-4 hidden items-center gap-x-4 lg:flex">
                    @foreach ($navigation as $group)
                        @if ($groupLabel = $group->getLabel())
                            {{-- MEGA MENU: nếu config/mega-menu.php có khai báo cho nhóm này, chia
                                 dropdown thành nhiều CỘT CÓ TIÊU ĐỀ (kiểu "Resources"/"Company" của
                                 các mega menu SaaS phổ biến) — thuần trình bày, không đụng tới
                                 ->navigationGroup() thật của Filament. Nhóm không có khai báo thì
                                 giữ nguyên hành vi gốc, chỉ tự chia cột CSS khi danh sách quá dài. --}}
                            @php
                                $subGroupMap = config('mega-menu.' . $groupLabel);
                                $subGroups   = [];

                                if ($subGroupMap) {
                                    foreach ($subGroupMap as $subLabel => $itemLabels) {
                                        $subGroups[$subLabel] = [];
                                    }
                                    $subGroups['Khác'] = [];

                                    foreach ($group->getItems() as $item) {
                                        $itemLabel = $item->getLabel();
                                        $placed    = false;

                                        foreach ($subGroupMap as $subLabel => $itemLabels) {
                                            if (in_array($itemLabel, $itemLabels, true)) {
                                                $subGroups[$subLabel][] = $item;
                                                $placed = true;
                                                break;
                                            }
                                        }

                                        if (! $placed) {
                                            $subGroups['Khác'][] = $item;
                                        }
                                    }

                                    // Bỏ cột rỗng (thường là "Khác" khi mọi mục đều khớp cấu hình).
                                    $subGroups = array_filter($subGroups);
                                }

                                // Tối đa 3 CỘT — nhiều tiêu đề hơn thì CSS Grid tự xuống hàng (grid-
                                // template-columns cố định 3, không phải đếm bao nhiêu tiêu đề rồi
                                // nới rộng thêm), tránh dropdown bị kéo quá rộng phải cuộn ngang.
                                $megaMenuCols = $subGroups ? min(count($subGroups), 3) : 1;

                                if (! $subGroups) {
                                    $flatCount    = count($group->getItems());
                                    $megaMenuCols = match (true) {
                                        $flatCount > 16 => 3,
                                        $flatCount > 8 => 2,
                                        default => 1,
                                    };
                                }

                                $megaMenuWidth = match ($megaMenuCols) {
                                    3 => '3xl',
                                    2 => 'lg',
                                    default => null,
                                };
                            @endphp

                            <x-filament::dropdown
                                placement="bottom-start"
                                teleport
                                :width="$megaMenuWidth"
                                :attributes="\Filament\Support\prepare_inherited_attributes($group->getExtraTopbarAttributeBag())"
                            >
                                <x-slot name="trigger">
                                    <x-filament-panels::topbar.item
                                        :active="$group->isActive()"
                                        :icon="$group->getIcon()"
                                    >
                                        {{ $groupLabel }}
                                    </x-filament-panels::topbar.item>
                                </x-slot>

                                @if ($subGroups)
                                    <div class="fi-mega-menu-grid" style="--fi-mega-menu-cols: {{ $megaMenuCols }};">
                                        @foreach ($subGroups as $subLabel => $subItems)
                                            <div class="fi-mega-menu-col">
                                                <x-filament::dropdown.header>{{ $subLabel }}</x-filament::dropdown.header>
                                                <x-filament::dropdown.list>
                                                    @foreach ($subItems as $item)
                                                        @php $itemIsActive = $item->isActive(); @endphp

                                                        <x-filament::dropdown.list.item
                                                            :badge="$item->getBadge()"
                                                            :badge-color="$item->getBadgeColor()"
                                                            :badge-tooltip="$item->getBadgeTooltip()"
                                                            :color="$itemIsActive ? 'primary' : 'gray'"
                                                            :href="$item->getUrl()"
                                                            :icon="$itemIsActive ? ($item->getActiveIcon() ?? $item->getIcon()) : $item->getIcon()"
                                                            tag="a"
                                                            :target="$item->shouldOpenUrlInNewTab() ? '_blank' : null"
                                                        >
                                                            {{ $item->getLabel() }}
                                                        </x-filament::dropdown.list.item>
                                                    @endforeach
                                                </x-filament::dropdown.list>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    @php
                                        $lists = [];

                                        foreach ($group->getItems() as $item) {
                                            if ($childItems = $item->getChildItems()) {
                                                $lists[] = [
                                                    $item,
                                                    ...$childItems,
                                                ];
                                                $lists[] = [];

                                                continue;
                                            }

                                            if (empty($lists)) {
                                                $lists[] = [$item];

                                                continue;
                                            }

                                            $lists[count($lists) - 1][] = $item;
                                        }

                                        if (empty($lists[count($lists) - 1])) {
                                            array_pop($lists);
                                        }
                                    @endphp

                                    <div
                                        @class(['fi-mega-menu' => $megaMenuCols > 1])
                                        @style(["column-count: {$megaMenuCols}" => $megaMenuCols > 1])
                                    >
                                        @foreach ($lists as $list)
                                            <x-filament::dropdown.list>
                                                @foreach ($list as $item)
                                                    @php
                                                        $itemIsActive = $item->isActive();
                                                    @endphp

                                                    <x-filament::dropdown.list.item
                                                        :badge="$item->getBadge()"
                                                        :badge-color="$item->getBadgeColor()"
                                                        :badge-tooltip="$item->getBadgeTooltip()"
                                                        :color="$itemIsActive ? 'primary' : 'gray'"
                                                        :href="$item->getUrl()"
                                                        :icon="$itemIsActive ? ($item->getActiveIcon() ?? $item->getIcon()) : $item->getIcon()"
                                                        tag="a"
                                                        :target="$item->shouldOpenUrlInNewTab() ? '_blank' : null"
                                                    >
                                                        {{ $item->getLabel() }}
                                                    </x-filament::dropdown.list.item>
                                                @endforeach
                                            </x-filament::dropdown.list>
                                        @endforeach
                                    </div>
                                @endif
                            </x-filament::dropdown>
                        @else
                            @foreach ($group->getItems() as $item)
                                <x-filament-panels::topbar.item
                                    :active="$item->isActive()"
                                    :active-icon="$item->getActiveIcon()"
                                    :badge="$item->getBadge()"
                                    :badge-color="$item->getBadgeColor()"
                                    :badge-tooltip="$item->getBadgeTooltip()"
                                    :icon="$item->getIcon()"
                                    :should-open-url-in-new-tab="$item->shouldOpenUrlInNewTab()"
                                    :url="$item->getUrl()"
                                >
                                    {{ $item->getLabel() }}
                                </x-filament-panels::topbar.item>
                            @endforeach
                        @endif
                    @endforeach
                </ul>
            @endif
        @endif

        <div
            @if (filament()->hasTenancy())
                x-persist="topbar.end.panel-{{ filament()->getId() }}.tenant-{{ filament()->getTenant()?->getKey() }}"
            @else
                x-persist="topbar.end.panel-{{ filament()->getId() }}"
            @endif
            class="ms-auto flex items-center gap-x-4"
        >
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::GLOBAL_SEARCH_BEFORE) }}

            @if (filament()->isGlobalSearchEnabled())
                @livewire(Filament\Livewire\GlobalSearch::class)
            @endif

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::GLOBAL_SEARCH_AFTER) }}

            @if (filament()->auth()->check())
                @if (filament()->hasDatabaseNotifications())
                    @livewire(Filament\Livewire\DatabaseNotifications::class, [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                    ])
                @endif

                <x-filament-panels::user-menu />
            @endif
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_END) }}
    </nav>
</div>
