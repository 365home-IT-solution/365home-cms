<div
        class="w-full p-12 flex flex-col justify-center items-center max-md:absolute max-md:top-[50%] max-md:translate-y-[-50%] max-md:bg-white/70 max-md:z-20">
    <div class="text-primary text-center font-bold md:text-4xl text-xl leading-tight">
        {{ $banner['title'] ?? '' }}
    </div>
    <h6 class="md:text-md text-sm text-gray-700 mt-4 text-center">{{ $banner['description'] ?? '' }}</h6>
    <div class="flex justify-center items-center">
        @if ($banner['cta_text'])
            <a href="{{ $banner['cta_link'] }}" class="mt-4">
                <x-bladethemev1::buttons.button :padding="'md:px-8 px-6 md:py-3 py-2'" :text_size="'md:text-xl text-lg'" :style="'storm'">
                    {{ $banner['cta_text'] }}
                </x-bladethemev1::buttons.button>
            </a>
        @endif
    </div>
    <ul class="flex flex-wrap justify-center gap-6 mt-8">
        @if ($contactGroup)
            @foreach ($contactGroup as $item)
                <li class="flex items-center">
                    <x-dynamic-component :component="$item['icon']" class="md:w-6 md:h-6 w-4 h-4 text-primary" />
                    <div class="text-gray-700 text-sm ml-2">{{ $item['value'] }}</div>
                </li>
            @endforeach
        @endif
    </ul>
</div>
