<div class="w-full flex items-center {{ $content_alignment === 'right' ? 'flex-row-reverse' : '' }} gap-x-8">
    @if ($media_file)
        <div class="flex-1 md:flex hidden justify-center items-center">
            <img class="object-contain" 
                style="height: {{ $media_file_height . 'px' }}; border-radius: {{ $border_radius . 'em !important' }};"
                src="{{ asset('storage/' . $media_file) }}" alt="{{ $media_file }}">
        </div>
    @endif

     <div class="flex-1 flex flex-col justify-center max-md:relative {{ $media_file ? '' : 'md:px-28' }}">
       @if ($icon)
            <div class="bg-primary text-white w-12 h-12 rounded-full flex justify-center items-center mb-4">
                <x-dynamic-component :component="$icon" class="block text-white md:w-5 md:h-5 w-4 h-4" />
            </div>
        @endif

        @if ($title)
            <div class="text-primary max-w-2xl mb-4 font-bold tracking-tight leading-none md:text-3xl text-2xl">
                {{ $title }}
            </div>
        @endif

        @if ($sub_title)
            <div class="font-medium text-lg">{{ $sub_title }}</div>
        @endif

        @if ($content)
            <div class="mb-4 post-content content-section text-xl text-gray-800 dark:text-gray-400">
                {!! $content !!}</div>
            <div class="md:px-28 text-justify"></div>
            <style>
                .content-section ul {
                    margin-top: 10px;
                }

                .content-section p {
                    padding-bottom: 10px;
                    line-height: 27px;
                    text-align: justify
                }

                .content-section ul li {
                    margin-bottom: 10px;
                }
            </style>
        @endif

        @if ($button_cta)
            <div>
                <a href="{{ $button_cta_link ?? '/' }}">
                    <x-bladethemev1::buttons.button :style="$button_cta_style">
                        {{ $button_cta }}
                    </x-bladethemev1::buttons.button>
                </a>
            </div>
        @endif
    </div>
</div>
