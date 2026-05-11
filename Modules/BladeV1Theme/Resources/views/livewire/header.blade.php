@php 
    $headerStyle = false;
    $headerStyleClassess = $headerStyle ? "fixed z-50 w-full" : "relative";
@endphp

<header class="{{ $headerStyleClassess }}">
    @if ($topbarConfig['show_top_bar'] && !$headerStyle)
        <x-bladethemev1::header.topbar
            :height="$topbarConfig['height']"
            :background_color="$topbarConfig['background_color']"
            :header_contacts="$topbarConfig['header_contacts']"
            :text_color="$topbarConfig['text_color']"
            :social_icon="$topbarConfig['social_icon']"
            :social_links="$topbarConfig['social_links']"
        />
    @endif

    <x-bladethemev1::header.header-main
        :data="new \Modules\BladeThemeV1\Types\HeaderData(
            logo: $logo,
            logoLightVersion: $logoLightVersion,
            logoConfig: $logoConfig,
            headerConfig: $headerMainConfig,
            navConfig: $navConfig,
            actionConfig: $actionConfig,
            headerStyle: $headerStyle,
            cartConfig: $cartConfig
        )"
        :menu="$menu"
        :topbarConfig="$topbarConfig"
    />
</header>

