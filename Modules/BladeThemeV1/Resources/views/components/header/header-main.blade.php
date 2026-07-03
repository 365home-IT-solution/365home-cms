@props([
    'data'               => new \Modules\BladeThemeV1\Types\HeaderData('', '', [], [], [], [], false),
    'menu'               => null,
    'authHeaderEnabled'  => false,
])

@php
    /** @var \Modules\BladeThemeV1\Types\HeaderData $data */
    // Logo configs
    $logoHeight = $data->logoConfig['height'] . 'px';
    $logoHeightMobile = ($data->logoConfig['height'] * 2/3) .'px';
    $logoPosition = $data->logoConfig['logo_position'] . 'left';
    $logoLink = $data->logoConfig['logo_link'];
    $logoStyle = $data->logoConfig['logo_style'];
    $cartConfig = $data->cartConfig['show_cart_icon'];

    // Header main configs
    $headerHeight = $data->headerConfig['height'] . 'px';
    $headerBackgroundColor = $data->headerConfig['background_color'];
    $addShadow = $data->headerConfig['add_shadow'];

    // Navigation bar configs
    $navStyle = $data->navConfig['navigation_style'];
    $navSize = 14;
    $navSpacing = $data->navConfig['nav_spacing'];
    $navUppercase = $data->navConfig['uppercase'];

    // Action configs
    $searchButtonConfig = [
        'visible' => $data->actionConfig['search_button']
    ];

    $ctaButtonConfig = [
        'visible' => $data->actionConfig['cta_button'],
        'label' => $data->actionConfig['cta_button_label'],
        'link' => $data->actionConfig['cta_button_link']
    ];

    // Logo image
    $logo =
        $logoStyle === 'light_version'
            ?
            $data->logoLightVersion
            :
            $data->logo;

    // Nav spacing classes
    $navSpacingClass = match ($navSpacing) {
        'xs' => 'gap-x-4',
        'sm' => 'gap-x-6',
        'md' => 'gap-x-8',
        'lg' => 'gap-x-10',
        'xl' => 'gap-x-12',
        '0' => '',
        default => '',
    };

    // Header luôn nền trắng + logo màu gốc (đồng bộ với hàng 2 tìm kiếm), không còn kiểu trong suốt trên trang chủ.
    $initBgColor = '#ffffff';
    $logoFilterExpr = "'none'";
@endphp

<div id="main-header-bar"
     x-data="{
        isSticky: false,
        mobileMenuOpen: false
    }"
     x-init="
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => {
                // Hysteresis: ngưỡng bật (80px) khác ngưỡng tắt (40px) để tránh isSticky
                // nhấp nháy liên tục (gây rung/giật) khi scrollY dao động quanh 1 mốc duy nhất.
                if (!isSticky && window.scrollY > 80) isSticky = true;
                else if (isSticky && window.scrollY < 40) isSticky = false;
                ticking = false;
            });
        }, { passive: true });
    "
     :class="{
        'shadow-md': {{ $addShadow ? 'true' : 'false' }} && isSticky,
     }"
     style="position: sticky; top: 0; overflow-anchor: none;"
     class="w-full z-[1200] transition-shadow duration-300 ease-in-out header-hero-sticky border-b border-gray-100">

    <div style="background-color: {{ $initBgColor }};">

        {{-- Hàng 1: logo / menu / actions — chiều cao cố định, không đổi khi cuộn (tránh giật) --}}
        <div class="w-full max-w-11xl md:px-8 px-4 mx-auto py-[8px]" style="height: {{ $headerHeight }};">
                <div class='flex flex-wrap items-center justify-between relative h-full'>
                    <!-- Mobile Header - Improved Layout -->
                    <div class="flex items-center justify-end w-full lg:hidden">
                        <!-- Mobile Actions -->
                        <div class="flex items-center gap-2">
                            <!-- Search Button Mobile (if visible) -->
                            @if($searchButtonConfig['visible'])
                                <x-bladethemev1::header.actions.search :searchButtonConfig="$searchButtonConfig"/>
                            @endif
                        </div>
                    </div>


                    <!-- Desktop Logo -->
                    <a href="{{ $logoLink }}"
                       class="hidden lg:flex items-center flex-shrink-0 order-1">
                        <img :style="{ height: '{{ $logoHeight }}', filter: {{ $logoFilterExpr }} }"
                             src="{{ asset('/storage/'.$logo) }}" alt="Logo"
                             class="transition-all duration-300"/>
                    </a>

                    <!-- Khối giữa: menu (căn giữa) khi ở đầu trang, thanh tìm kiếm gọn thế chỗ khi cuộn.
                         Dùng grid xếp chồng 1 ô (thay vì flex chia đều) để trong lúc crossfade,
                         menu và pill KHÔNG cùng chiếm chỗ ngang hàng -> tránh bị bóp/wrap chữ. -->
                    <div class="hidden lg:grid flex-1 min-w-0 px-6 order-2" style="grid-template-columns: minmax(0,1fr);">
                        <!-- Desktop Navigation: ẩn khi cuộn (isSticky) -->
                        <div class="col-start-1 row-start-1 flex items-center justify-center min-w-0"
                             x-show="!isSticky"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100"
                             x-transition:leave-end="opacity-0">
                            <ul class="flex items-center whitespace-nowrap {{ $navSpacingClass }}">
                                @if (!empty($menu?->menuItems))
                                    @foreach ($menu->menuItems as $menuItem)
                                        @livewire('bladethemev1::menu-item', [
                                        'menuItem' => $menuItem,
                                        'depth' => 1,
                                        'loop' => $loop,
                                        'navStyle' => $navStyle,
                                        'navSize' => $navSize,
                                        ], key($menuItem->id))
                                    @endforeach
                                @endif
                            </ul>
                        </div>

                        {{-- Chỗ trống để thanh tìm kiếm gọn (compact pill) "teleport" vào khi cuộn --}}
                        <div id="header-search-slot"
                             class="col-start-1 row-start-1 flex w-full min-w-0 items-center justify-center"
                             x-show="isSticky" x-cloak></div>
                    </div>

                    <!-- Desktop Action buttons -->
                    <div class="hidden lg:block order-3">
                            <div class="flex items-center gap-4">
                                <!-- Search -->
                                @if($searchButtonConfig['visible'])
                                    <x-bladethemev1::header.actions.search :searchButtonConfig="$searchButtonConfig"/>
                                @endif

                                <!-- CTA Button -->
                                @if($ctaButtonConfig['visible'])
                                    <x-bladethemev1::header.actions.cta-button :ctaButtonConfig="$ctaButtonConfig"/>
                                @endif

                                <!-- Auth Button -->
                                @if($authHeaderEnabled)
                                    <x-bladethemev1::header.actions.auth-button />
                                @endif
                            </div>
                        </div>
                </div>
            </div>

        {{-- Hàng 2: form tìm kiếm đầy đủ — co/giãn mượt bằng grid-template-rows (không dùng height:auto) --}}
        <div class="hidden lg:grid overflow-hidden transition-[grid-template-rows] duration-300 ease-in-out"
             :style="{ gridTemplateRows: isSticky ? '0fr' : '1fr' }">
            <div class="min-h-0 overflow-hidden">
                <div class="w-full max-w-7xl md:px-8 px-4 mx-auto pb-3">
                    @livewire('bladethemev1::hero-section', ['headerRow' => true])
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    /* Sau khi scroll xuống (nền trắng): chữ màu primary, gạch chân primary */
    .header-hero-sticky .main-menu-item {
        color: var(--color-primary) !important;
    }
    .header-hero-sticky .main-menu-item:hover {
        color: var(--color-primary) !important;
        opacity: 0.8;
    }
    .header-hero-sticky .main-menu-item::after {
        background: var(--color-primary) !important;
    }

    /* Custom styles for mobile optimization */
    .fixed-header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 50;
    }

    /* Ensure mobile menu doesn't interfere with content */
    @media (max-width: 768px) {
        .fixed-header + * {
            margin-top: {{ $headerHeight }};
        }

        /* Mobile responsive adjustments */
        .container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .mx-\[110px\] {
            margin-left: 0;
            margin-right: 0;
        }

        .px-\[12px\] {
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
    }

    /* Smooth transitions for mobile menu */
    [x-cloak] {
        display: none !important;
    }

    /* Touch-friendly button sizes */
    @media (max-width: 768px) {
        button {
            min-height: 44px;
            min-width: 44px;
        }
    }

    /* Mobile menu styling */
    @media (max-width: 768px) {
        .mobile-menu-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 50;
        }
    }
</style>