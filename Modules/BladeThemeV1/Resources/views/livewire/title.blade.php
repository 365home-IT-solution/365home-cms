<div class="relative max-w-screen-xl  mx-auto">
    @if ($title || $description)
        <div class="max-w-2xl {{ $title_style }}">
            @if ($title)
                <h2 style="color: {{ $title_color }};" class="{{$font_size}} font-bold mb-2">
                    {{ $title }}
                </h2>
            @endif
            @if ($description)
                <div class="text-base max-w-6xl mt-2" style="color: {{ $description_color }};">
                    {{ $description }}
                </div>
            @endif
        </div>
    @endif
</div>
