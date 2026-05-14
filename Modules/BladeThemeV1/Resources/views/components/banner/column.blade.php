@props([
    'banners',
    'contact_group',
    'show_pagination',
    'show_navigation',
    'content_alignment',
    'height_image'
])

@foreach ($banners as $banner)
    <div class="swiper-slide relative">
        <div class="bg-gray-50 font-sans relative mx-auto overflow-hidden">
            <div class="grid md:grid-cols-2 max-sm:gap-6 max-md:relative">
                @if ($content_alignment === 'right')
                    <x-bladethemev1::banner.column.banner-image
                            :alignment="$content_alignment"
                            :banner="$banner"
                            :heightImage="$height_image"
                    />
                @endif

                <x-bladethemev1::banner.column.banner-content
                        :banner="$banner"
                        :alignment="$content_alignment"
                        :contactGroup="$contact_group"
                />

                @if ($content_alignment !== 'right')
                    <x-bladethemev1::banner.column.banner-image
                            :alignment="$content_alignment"
                            :banner="$banner"
                            :heightImage="$height_image"
                    />
                @endif

            </div>
            <x-bladethemev1::banner.column.decorative-circles
                    :alignment="$content_alignment"
                    :banner="$banner"
            />
        </div>
    </div>
@endforeach
