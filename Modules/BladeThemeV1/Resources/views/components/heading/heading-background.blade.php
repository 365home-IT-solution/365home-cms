@props([
    'heading',
    'heading_color' => '#ddd',
    'heading_sub',
    'heading_sub_color' => '#ffffff',
    'heading_style',
    'heading_background_image',
])

<div class="relative bg-white shadow-lg overflow-hidden">
    <img src="{{ asset('/storage/' . $heading_background_image) }}" alt="{{ $heading }}"
        class="w-full h-32 lg:h-48 object-cover rounded">
    <div class="absolute w-full top-[50%] translate-y-[-50%] z-10">
        <div class="max-w-screen-xl mx-auto md:px-8 px-4">
            <div class="max-w-2xl {{$heading_style}}">
                @if ($heading)
                    <h2 style="color: {{ $heading_color }}" class="md:text-3xl text-2xl font-bold uppercase">
                        {{ $heading }}
                    </h2>
                @endif
                @if ($heading_sub)
                    <div class="text-base max-w-6xl mt-2" style="color: {{ $heading_sub_color }};">
                        {{ $heading_sub }}
                    </div>
                @endif
            </div>
        </div>
    </div>
    <div class="absolute inset-0 bg-black opacity-50"></div>
</div>
