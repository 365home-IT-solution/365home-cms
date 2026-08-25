@props([
    'data' => new \Modules\BladeThemeV1\Types\Footer\FooterBottomData('', '', '', [])
])

@php
    /** @var \Modules\BladeThemeV1\Types\Footer\FooterBottomData $data */
    $baseColor = $data->baseColor;
    $backgroundColor = $data->backgroundColor;
    $copyright = $data->copyright;
    $bottomMenu = $data->bottomMenu;
@endphp

<div style="background: {{ $backgroundColor }}" class="border-t">
    <div class="max-w-11xl mx-auto md:px-8 px-4">
        <div style="color: {{ $baseColor }}" class="py-6 md:flex justify-between items-center">
            <div class="text-sm mb-4 md:mb-0 max-md:text-center">
                © {{ date('Y') }}.  Copyright by <a href="https://goldenbeeltd.vn">Golden Bee</a>  
            </div>
            @if($bottomMenu)
                <div class="flex flex-wrap gap-6 text-sm max-md:justify-center">
                    @foreach($bottomMenu as $item)
                        <a href="{{ $item['url'] }}"
                           class="hover:text-primary transition-colors hover:underline">
                            {{ $item['title'] }}
                        </a>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</div>
