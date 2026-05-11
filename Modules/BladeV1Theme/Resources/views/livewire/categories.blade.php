
<style>
    .owl-nav-{{ $uniqueId }} {
        position: absolute;
        transform: translateY(-50%);
        top: 50%;
        width: 100%;
    }

    .owl-carousel-{{ $uniqueId }} .owl-nav button.owl-prev,
    .owl-carousel-{{ $uniqueId }} .owl-nav button.owl-next {
        background: hsla(240, 1%, 48%, .102) !important;
    }

    .owl-nav-{{ $uniqueId }} .owl-prev,
    .owl-nav-{{ $uniqueId }} .owl-next {
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

    .owl-nav-{{ $uniqueId }} .owl-prev {
        left: -50px;
    }

    .owl-nav-{{ $uniqueId }} .owl-next {
        right: -50px;
    }

    @media screen and (max-width: 768px) {
        .owl-nav-{{ $uniqueId }} .owl-prev {
            left: -20px;
        }

        .owl-nav-{{ $uniqueId }} .owl-next {
            right: -20px;
        }
    }
</style>

<div class="container mx-auto">
    <h2 class="mt-4 mb-2 text-center text-4xl font-bold">Khám phá điều mới lạ tại LocalHome</h2>

    <div class="px-10">
        <div class="owl-carousel owl-carousel-{{ $uniqueId }}" id="owl-carousel-{{ $uniqueId }}">
            @foreach ($categories as $item)
            <div class="lg:p-4 p-0 md:p-2">
                <div class="bg-gray-50 rounded-2xl overflow-hidden flex flex-col">
                    <!-- Hình ảnh -->
                    <a class="relative block pt-[60%] overflow-hidden"  href="{{ route('category.detail', ['slug' => $item->c3_slug .'-'. $item->c2_slug]) }}">
                        <img src="{{ asset( 'storage/' .$item->image) }}"
                             alt="{{ $item->c3_name }}"
                             class="w-full h-full object-cover top-0 bottom-0 left-0 right-0 absolute transition hover:opacity-80" />
                        <div
                                class="absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-rose-500 to-transparent flex items-end justify-center text-white text-sm font-medium pb-2">
                        </div>
                    </a>

                    <!-- Nội dung -->
                    <div class="p-5">
                        <p class="text-start text-sm text-gray-400 line-clamp-1 mb-2">CHI NHÁNH: {{ $item->c2_name }}, {{ $item->c1_name }}</p>
                        <h6 class="text-start text-xl font-bold text-gray-800 line-clamp-1 mb-2">
                            <a href="{{ route('category.detail', ['slug' => $item->c3_slug .'-'. $item->c2_slug]) }}">
                                Home – {{ $item->c3_name }}, {{ $item->c2_name }}
                            </a>
                        </h6>
                        <p class="text-start text-sm text-gray-400 line-clamp-4 mb-4">{{ $item->c3_dep }}</p>
                        <a href="{{ route('category.detail', ['slug' => $item->c3_slug .'-'. $item->c2_slug]) }}"
                           class="w-full bg-white text-center gap-2 px-4 py-3 text-sm leading-5 flex items-center justify-center border border-rose-500 text-rose-500 rounded-2xl">
                            Khám phá ngay
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.00012 18L18.0001 6" stroke="rgb(255 86 107)" stroke-width="3"
                                      stroke-linecap="round" stroke-linejoin="round"></path>
                                <path d="M8.25 6H18V15.75" stroke="rgb(255 86 107)" stroke-width="3"
                                      stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </a>


                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/flowbite@3.1.2/dist/flowbite.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
        integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        $(document).ready(function () {
            // Khởi tạo Owl Carousel với unique ID
            $('#owl-carousel-{{ $uniqueId }}').owlCarousel({
                loop: true,
                margin: 10,
                responsiveClass: true,
                autoplay: true,
                autoplayTimeout: 3000,
                nav: true,
                navClass: ['owl-nav-{{ $uniqueId }}', 'owl-nav-{{ $uniqueId }}'],
                responsive: {
                    0: {
                        items: 1,
                        nav: true
                    },
                    768: {
                        items: 2,
                    },
                    1000: {
                        items: 3,
                    }
                }
            });
        });
    </script>
</div>