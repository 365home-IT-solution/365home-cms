@props([
    'heading', 
    'heading_color' => '#1f2937',
    'heading_sub' => null,
    'heading_sub_color' => '#67748e',
    'heading_style' => 'mx-auto text-center'
])

<div class="relative max-w-screen-xl md:px-8 px-4 mx-auto">
    @if ($heading || $heading_sub)
        <div class="max-w-2xl {{ $heading_style }} md:mb-12 mb-2 mt-12">
            @if ($heading)
                <h2 style="color: {{ $heading_color }};" class="md:text-3xl text-2xl font-bold mb-2">
                    {{ $heading }}
                </h2>
            @endif
            @if ($heading_sub)
                <div class="text-base max-w-6xl mt-2" style="color: {{ $heading_sub_color }};">
                    {{ $heading_sub }}
                </div>
            @endif
        </div>
    @endif
</div>
