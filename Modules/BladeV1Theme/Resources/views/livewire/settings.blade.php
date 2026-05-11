<style>
    /* Scope CSS to the unique carousel instance using the uniqueId */
    .owl-carousel-{{ $uniqueId }} .owl-nav {
        position: absolute;
        transform: translateY(-50%);
        top: 50%;
        width: 100%;
    }

    .owl-carousel-{{ $uniqueId }} .owl-nav button.owl-prev,
    .owl-carousel-{{ $uniqueId }} .owl-nav button.owl-next {
        background: hsla(240, 1%, 48%, .102) !important;
    }

    .owl-carousel-{{ $uniqueId }} .owl-nav .owl-prev,
    .owl-carousel-{{ $uniqueId }} .owl-nav .owl-next {
        align-items: center;
        background: hsla(240, 1%, 48%, .102);
        border-radius: 28px;
        display: flex;
        height: 36px;
        justify-content: center;
        margin: 0;
        position: absolute;
        text-align: center;
        top: 40%;
        width: 36px;
    }

    .owl-carousel-{{ $uniqueId }} .owl-nav .owl-prev {
        left: -50px;
    }

    .owl-carousel-{{ $uniqueId }} .owl-nav .owl-next {
        right: -50px;
    }

    .owl-carousel-{{ $uniqueId }} .owl-stage {
        display: flex;
        align-items: stretch;
    }

    @media screen and (max-width: 768px) {
        .owl-carousel-{{ $uniqueId }} .owl-nav .owl-prev {
            left: -20px;
        }

        .owl-carousel-{{ $uniqueId }} .owl-nav .owl-next {
            right: -20px;
        }
    }

    .owl-carousel-{{ $uniqueId }}.owl-custom-nav .owl-nav {
        display: flex;
    }
</style>
<div class="md:px-8 px-4">
    <div class="owl-carousel owl-carousel-{{ $uniqueId }} @if(!empty($settings) && is_array($settings) && count($settings) > 3) owl-custom-nav @endif">
        @if(!empty($settings) && is_array($settings))
            @foreach ($settings as $item)
                @php
                    $imagePath = !empty($item['image']) && is_array($item['image']) && !empty(array_values($item['image'])[0])
                        ? array_values($item['image'])[0]
                        : 'https://localhome.vn/uploads/2025/02/z6368388452535_3a90aaf7662bc284fd6ef777290efff7-1536x2048.jpg';
                @endphp
                <div class="bg-gray-50 rounded-2xl overflow-hidden flex flex-col h-full">
                    <!-- Hình ảnh -->
                    <a class="relative block pt-[60%] overflow-hidden" href="{{ $item['url'] ?? '#' }}">
                        <img src="{{ asset('storage/' . $imagePath) }}"
                             alt="{{ $item['title'] ?? 'No title' }}"
                             class="w-full h-full object-cover top-0 bottom-0 left-0 right-0 absolute transition hover:opacity-80" />
                        <div class="absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-primary to-transparent flex items-end justify-center text-white text-sm font-medium pb-2">
                        </div>
                    </a>

                    <!-- Nội dung -->
                    <div class="p-5 flex-1 flex flex-col">
                        <p class="text-start text-sm text-gray-400 line-clamp-1 mb-2">{{ $item['branch'] ?? 'No branch' }}</p>
                        <h6 class="text-start text-xl font-bold text-gray-800 line-clamp-1 mb-2">
                            <a href="{{ $item['url'] ?? '#' }}">{{ $item['title'] ?? 'No title' }}</a>
                        </h6>
                        <p class="text-start text-sm text-gray-400 line-clamp-4 mb-4">{{ $item['desciption'] ?? 'No description' }}</p>
                        <a href="{{ $item['url'] ?? '#' }}"
                           class="w-full bg-white text-center gap-2 px-4 py-3 text-sm leading-5 flex items-center justify-center border border-primary text-primary rounded-2xl mt-auto">
                            {{ $item['button'] ?? 'Khám phá ngay' }}
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-up-right-icon lucide-arrow-up-right"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>
                        </a>
                    </div>
                </div>
            @endforeach
        @else
            <div>No settings data available.</div>
        @endif
    </div>

    <!-- Owl Carousel JS -->
    <script>
        $(document).ready(function() {
            // Initialize Owl Carousel with unique class
            $('.owl-carousel-{{ $uniqueId }}').owlCarousel({
                loop: true,
                margin: 30,
                responsiveClass: true,
                autoplay: true,
                autoplayTimeout: 3000,
                nav: true,
                responsive: {
                    0: { items: 1 },
                    768: { items: 2 },
                    1000: { items: 3 }
                }
            });
        });
    </script>
</div>