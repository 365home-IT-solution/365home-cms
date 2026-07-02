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
    $headerSticky = $data->headerConfig['fixed'];
    $header_style = $data->headerStyle;



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

    $initialBackground = $header_style ? 'background-color: transparent;' : "background-color: $headerBackgroundColor;";
    $initBgColor = $header_style ? 'transparent' : $headerBackgroundColor;
    // Homepage: transparent→white khi scroll, logo invert→gốc. Non-homepage: luôn trắng, logo gốc
    $logoFilterExpr = $header_style
        ? "!isSticky ? 'brightness(0) invert(1)' : 'none'"
        : "'none'";
@endphp

<div id="main-header-bar"
     x-data="{
        isSticky: false,
        mobileMenuOpen: false
    }"
     x-init="window.addEventListener('scroll', () => {
        isSticky = window.scrollY > 60;
        @if($headerSticky && !$header_style)
        if (isSticky) {
            $el.classList.add('fixed', 'top-0', 'left-0', 'right-0');
        } else {
            $el.classList.remove('fixed', 'top-0', 'left-0', 'right-0');
        }
        @endif
    }, { passive: true })"
     :class="{
        'shadow-md': (isSticky || {{ !$header_style ? 'true' : 'false' }}) && {{ $addShadow ? 'true' : 'false' }},
        'border-b border-gray-100': isSticky || {{ !$header_style ? 'true' : 'false' }},
        'backdrop-blur-sm': !isSticky && {{ $header_style ? 'true' : 'false' }},
        'header-hero-transparent': !isSticky && {{ $header_style ? 'true' : 'false' }},
        'header-hero-sticky': isSticky || {{ !$header_style ? 'true' : 'false' }},
     }"
     class="w-full z-[1200] transition-all duration-300 ease-in-out {{ $header_style ? 'fixed top-0 left-0 right-0' : 'relative' }}">

    <div class="flex items-center transition-all duration-300 ease-in-out"
         :style="{ height: '{{ $headerHeight }}', backgroundColor: (isSticky || {{ !$header_style ? 'true' : 'false' }}) ? 'white' : '{{ $initBgColor }}' }">

        <div class="w-full md:px-8 px-4 mx-auto py-[8px]">
                <div class='flex flex-wrap items-center justify-between relative'>
                    <!-- Mobile Header - Improved Layout -->
                    <div class="flex items-center justify-between w-full lg:hidden">
                        <!-- Mobile Logo -->
                        <a href="{{ $logoLink }}" class="flex items-center flex-shrink-0">
                            <img :style="{ height: '{{ $logoHeightMobile }}', maxWidth: '250px', filter: {{ $logoFilterExpr }} }"
                                 src="{{ asset('/storage/'.$logo) }}"
                                 alt="Logo"
                                 class="max-w-none transition-all duration-300"/>
                        </a>

                        <!-- Mobile Actions -->
                        <div class="flex items-center gap-2">
                            <!-- Search Button Mobile (if visible) -->
                            @if($searchButtonConfig['visible'])
                                <x-bladethemev1::header.actions.search :searchButtonConfig="$searchButtonConfig"/>
                            @endif

                            <!-- Mobile Menu Toggle -->
                            <button data-drawer-target="drawer-navigation" data-drawer-show="drawer-navigation"
                                    class="inline-flex border border-borderGray text-borderGray p-2 rounded-md text-center focus:outline-none focus:ring-2 focus:ring-gray-200 transition duration-300 ease-in-out min-w-[30px] min-h-[30px]">
                                <!-- Grid Menu Icon -->
                                <svg class="w-12 h-12" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M256 256m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M455.111111 256m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M654.222222 256m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M256 455.111111m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M455.111111 455.111111m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M654.222222 455.111111m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M256 654.222222m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M455.111111 654.222222m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                    <path d="M654.222222 654.222222m56.888889 0l0 0q56.888889 0 56.888889 56.888889l0 0q0 56.888889-56.888889 56.888889l0 0q-56.888889 0-56.888889-56.888889l0 0q0-56.888889 56.888889-56.888889Z" fill="currentColor"></path>
                                </svg>
                            </button>
                        </div>
                    </div>


                    <!-- Desktop Logo -->
                    <a href="{{ $logoLink }}"
                       class="hidden lg:flex items-center flex-shrink-0">
                        <img :style="{ height: '{{ $logoHeight }}', filter: {{ $logoFilterExpr }} }"
                             src="{{ asset('/storage/'.$logo) }}" alt="Logo"
                             class="transition-all duration-300"/>
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden lg:flex items-center">
                        <ul class="flex items-center {{ $navSpacingClass }}">
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

                    <!-- Desktop Action buttons -->
                    @if($searchButtonConfig['visible'] || $ctaButtonConfig['visible'] || $authHeaderEnabled)
                        <div class="hidden lg:block">
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
                    @endif
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