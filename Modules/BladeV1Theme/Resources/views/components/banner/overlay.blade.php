@props(['banners', 'contact_group', 'show_pagination', 'show_navigation', 'content_alignment', 'height_image'])

@foreach ($banners as $banner)
    <div class="swiper-slide relative">
        @if (!empty($banner['image']))
            <img src="{{ asset('/storage/' . $banner['image']) }}"
                 alt="Ảnh banner" loading="lazy" class="w-full">
        @endif

        @if (
            (isset($banner['title']) && $banner['title']) ||
            (isset($banner['description']) && $banner['description']) ||
            (isset($banner['cta_text'], $banner['cta_link']) && $banner['cta_text'] && $banner['cta_link'])
        )
            @php
                $alignmentClasses = [
                    'left' => 'me-auto text-start item-start',
                    'center' => 'mx-auto text-center items-center',
                    'right' => 'ms-auto text-justify items-center',
                ][$content_alignment];
            @endphp

            <div class="max-w-screen-xl mx-auto md:px-8 px-4 absolute inset-0 flex flex-col justify-center {{ $alignmentClasses }}">
                <div class="w-full">
                    <div class="max-w-3xl px-2 flex flex-col justify-center {{ $alignmentClasses }} {{ $content_position === 'content-bottom' ? 'hidden md:flex' : '' }}">
                        @if ($banner['title'])
                            <div data-aos="fade-up" data-aos-delay="200" data-aos-duration="200"
                                 class="md:text-4xl text-xl font-bold text-primary mb-2 md:mb-4 banner-title">
                                {!! $banner['title'] ?? '' !!}</div>
                        @endif
                        @if ($banner['description'] ?? '')
                            <p data-aos="fade-up" data-aos-delay="400" data-aos-duration="400"
                               class="text-sm sm:text-base md:text-lg lg:text-xl text-black mb-2 md:mb-4 banner-description">
                                {!! $banner['description'] ?? '' !!}</p>
                        @endif
                        @if ($banner['cta_text'] && $banner['cta_link'])
                            <a href="{{ $banner['cta_link'] }}" class="">
                                <x-bladethemev1::buttons.button :padding="'md:px-8 px-6 md:py-3 py-2'" :text_size="'md:text-xl text-lg'" :style="'storm'">
                                    {{ $banner['cta_text'] }}
                                </x-bladethemev1::buttons.button>
                            </a>
                        @endif
                        @if ($contactGroup)
                            <ul class="flex flex-wrap justify-center gap-6 mt-8">
                                @foreach ($contactGroup as $item)
                                    <li class="flex items-center">
                                        <x-dynamic-component :component="$item['icon']"
                                                             class="md:w-6 md:h-6 w-4 h-4 text-white" />
                                        <div class="text-white text-sm ml-2">{{ $item['value'] }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
@endforeach
