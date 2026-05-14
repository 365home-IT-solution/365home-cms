<div>
    {{-- Hiển thị trực tiếp banners mà không cần check locale --}}
    @if ($banners)
        <div class="swiper {{ $uniqueId }}" style="width: {{$width_swiper}}%;">
            <div class="swiper-wrapper banner-skeleten">
                @if ($layout === 'column')
                    <x-bladethemev1::banner.column
                            :banners="$banners"
                            :contact_group="$contact_group"
                            :show_pagination="$show_pagination"
                            :show_navigation="$show_navigation"
                            :content_alignment="$content_alignment"
                            :height_image="$height_image"
                    />
                @else
                    <x-bladethemev1::banner.overlay
                            :banners="$banners"
                            :contact_group="$contact_group"
                            :show_pagination="$show_pagination"
                            :show_navigation="$show_navigation"
                            :content_alignment="$content_alignment"
                            :height_image="$height_image"
                    />
                @endif
            </div>
            @if ($show_pagination)
                <div class="swiper-pagination"></div>
            @endif
            @if ($show_navigation)
                <div class="swiper-button-next"></div>
                <div class="swiper-button-prev"></div>
            @endif
        </div>
    @endif

    <style>
        .banner-skeleten {
            height: {{ $height_swiper }}% !important;
        }
        .swiper-slide {
            width: 100%;
            height: 100%;
            background-position: center;
            background-repeat: no-repeat;
            background-size: contain;
        }
        .swiper-slide img {
            width: 100%;
            height: auto;
            max-height: 100%;
            object-fit: cover;
        }
        @media (max-width: 1024px) {
            .swiper-wrapper {
                height: 90%;
            }
        }
        @media (max-width: 768px) {
            .banner-skeleten {
                height: auto !important;
            }
        }
    </style>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        let swiperConfig = {
            pagination: {
                el: '.{{ $uniqueId }} .swiper-pagination',
                clickable: true,
            },
            navigation: {
                nextEl: '.{{ $uniqueId }} .swiper-button-next',
                prevEl: '.{{ $uniqueId }} .swiper-button-prev',
            },
            slidesPerView: 1,
            autoplay: {
                delay: 4000,
                disableOnInteraction: false,
            },
        };

        const swiper = new Swiper('.{{ $uniqueId }}', swiperConfig);
    });
</script>