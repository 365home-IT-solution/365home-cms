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
                            <path d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z" />
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