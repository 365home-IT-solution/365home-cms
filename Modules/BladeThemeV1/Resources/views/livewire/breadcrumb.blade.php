@php
    $currentUrl = request()->path();
    $primaryColor = $this->primaryColor;
    $darkerPrimaryColor = dechex(hexdec(substr($primaryColor, 1)) - 0x222222);
    $darkerPrimaryColor = '#' . str_pad($darkerPrimaryColor, 6, '0', STR_PAD_LEFT);

    $breadcrumbSchemaItems = [[
        '@type'    => 'ListItem',
        'position' => 1,
        'name'     => 'Trang chủ',
        'item'     => url('/'),
    ]];
    foreach ($breadcrumbs as $index => $breadcrumb) {
        $breadcrumbSchemaItems[] = [
            '@type'    => 'ListItem',
            'position' => $index + 2,
            'name'     => $breadcrumb['title'],
            'item'     => $breadcrumb['url'],
        ];
    }
    $breadcrumbSchema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $breadcrumbSchemaItems,
    ];
@endphp
<div class="">
    @if ($currentUrl !== '/' && count($breadcrumbs) > 0)
        <script type="application/ld+json">{!! json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
        <nav aria-label="Breadcrumb" class="max-w-screen-xl text-gray-900 mx-auto text-sm md:px-8 px-4">
            <ol class="list-none p-0 inline-flex flex-wrap py-4">
                <li class="flex items-center">
                    <a href="/" style="color: {{ $primaryColor }}; transition: color 0.15s ease-in-out;" onmouseover="this.style.color='{{ $darkerPrimaryColor }}'" onmouseout="this.style.color='{{ $primaryColor }}'">
                        Trang chủ
                    </a>
                </li>
                @foreach ($breadcrumbs as $index => $breadcrumb)
                    <li class="flex items-center">
                        <svg class="w-3 h-3 mx-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" style="fill: {{ $primaryColor }};">
                            <use href="#i-crumb-chevron" />
                        </svg>
                        @if ($index < count($breadcrumbs) - 1)
                            <a class="" href="{{ $breadcrumb['url'] }}" style="color: {{ $primaryColor }}; transition: color 0.15s ease-in-out;" onmouseover="this.style.color='{{ $darkerPrimaryColor }}'" onmouseout="this.style.color='{{ $primaryColor }}'">
                                {{ $breadcrumb['title'] }}
                            </a>
                        @else
                            <span class="text-gray-500">{{ $breadcrumb['title'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif
</div>