<div class="swiper {{ $uniqueId }}" id="{{ $uniqueId }}">
    <div class="swiper-wrapper flex">
        @isset($posts)
            @foreach ($posts as $post)
                <div class="swiper-slide py-1">
                    @switch($config['style'] ?? 'default')
                        @case('overlay')
                            @include('bladethemev1::components.posts.overlay', [
                                'post' => $post,
                            ])
                        @break

                        @case('card')
                            @include('bladethemev1::components.posts.card', [
                                'post' => $post,
                            ])
                        @break

                        @case('minimal')
                            @include('bladethemev1::components.posts.minimal', [
                                'post' => $post,
                            ])
                        @break

                        @default
                            @include('bladethemev1::components.posts.default', [
                                'post' => $post,
                                'primaryColor' => $primaryColor,
                            ])
                    @endswitch
                </div>
            @endforeach
        @endisset
    </div>
    @if ($config['show_pagination'] ?? null)
        <div class="swiper-pagination"></div>
    @endif
    @if ($config['show_navigation'] ?? null)
        <div class="swiper-button-next"></div>
        <div class="swiper-button-prev"></div>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('{{ $uniqueId }}')) {
            let swiperConfig = {
                loop: true,
                slidesPerView: 1,
                spaceBetween: @json(($config['space_between'] ?? null !== '' ? $config['space_between'] ?? 20 : 20) ?? 20),
                pagination: {
                    el: '.{{ $uniqueId }} .swiper-pagination',
                    clickable: true,
                },
                navigation: {
                    nextEl: '.{{ $uniqueId }} .swiper-button-next',
                    prevEl: '.{{ $uniqueId }} .swiper-button-prev',
                },
                breakpoints: {
                    640: {
                        slidesPerView: {{ $columns['sm'] }},
                    },
                    768: {
                        slidesPerView: {{ $columns['md'] }},
                    },
                    1024: {
                        slidesPerView: {{ $columns['lg'] }},
                    },
                },
            }

            if (@json(($config['autoplay_speed'] ?? null !== '' ? $config['autoplay_speed'] ?? 0 : 0) ?? 0) > 1000) {
                swiperConfig.autoplay = {
                    delay: @json(($config['autoplay_speed'] ?? null !== '' ? $config['autoplay_speed'] ?? 3000 : 3000) ?? 3000),
                    disableOnInteraction: false,
                };
                swiperConfig.loop = true;
            }

            const swiper = new Swiper("#{{ $uniqueId }}", swiperConfig);
        }
    });
</script>
