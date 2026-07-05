<div>
    @if (!empty($provinces))
    <section class="py-12 bg-gray-50 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">

            {{-- Header --}}
            <div class="flex items-end justify-between mb-8">
                <div>
                    <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Khám phá địa điểm</h2>
                    {{-- <p class="text-gray-500 mt-1 text-sm">Tìm phòng nghỉ tại các tỉnh thành có chi nhánh 365 HOME</p> --}}
                </div>
                {{-- <a href="{{ route('product.search') }}"
                    class="hidden sm:flex items-center gap-1 text-sm font-semibold text-teal-600 hover:text-teal-700 transition-colors shrink-0">
                    Xem tất cả
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a> --}}
            </div>

        </div>

        {{-- Swiper (full-width để slide ra cạnh màn hình) --}}
        <div class="relative px-4 sm:px-6 max-w-7xl mx-auto">

            {{-- Nút prev/next --}}
            <button id="province-prev"
                class="absolute left-0 top-1/2 -translate-y-1/2 z-10 w-10 h-10 bg-white rounded-full shadow-md flex items-center justify-center text-gray-600 hover:text-teal-600 hover:shadow-lg transition-all duration-200 -translate-x-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <button id="province-next"
                class="absolute right-0 top-1/2 -translate-y-1/2 z-10 w-10 h-10 bg-white rounded-full shadow-md flex items-center justify-center text-gray-600 hover:text-teal-600 hover:shadow-lg transition-all duration-200 translate-x-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div class="swiper province-swiper overflow-hidden">
                <div class="swiper-wrapper">
                    @foreach ($provinces as $province)
                    <div class="swiper-slide">
                        <a href="{{ route('product.search', ['location' => $province['slug']]) }}"
                            class="group block overflow-hidden rounded-2xl shadow-md hover:shadow-xl transition-shadow duration-300"
                            style="position:relative; padding-top:72%;">

                            {{-- Ảnh --}}
                            @if ($province['image'])
                                <img src="{{ $province['image'] }}"
                                    alt="{{ $province['name'] }}"
                                    loading="lazy"
                                    class="group-hover:scale-105 transition-transform duration-500"
                                    style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;" />
                            @else
                                <div style="position:absolute; inset:0; background:linear-gradient(135deg,#ccfbf1,#99f6e4);"></div>
                            @endif

                            {{-- Gradient overlay --}}
                            <div style="position:absolute; inset:0; background:linear-gradient(to top, rgba(0,0,0,0.65) 0%, rgba(0,0,0,0.1) 50%, transparent 100%);"></div>

                            {{-- Tên tỉnh --}}
                            <div style="position:absolute; bottom:0; left:0; right:0; padding:14px 16px;">
                                <p style="color:#fff; font-size:15px; font-weight:800; margin:0; line-height:1.3; text-shadow:0 1px 4px rgba(0,0,0,.4);">
                                    {{ $province['name'] }}
                                </p>
                                {{-- <p style="color:rgba(255,255,255,.75); font-size:11px; margin:3px 0 0; font-weight:500;">
                                    {{ $province['branch_count'] }} chi nhánh · {{ $province['room_count'] }} phòng
                                </p> --}}
                            </div>

                        </a>
                    </div>
                    @endforeach
                </div>

                {{-- Pagination dots --}}
                <div class="swiper-pagination province-pagination mt-6"></div>
            </div>
        </div>
    </section>

    <script>
    (function () {
        function initProvinceSwiper() {
            if (typeof Swiper === 'undefined') return;
            new Swiper('.province-swiper', {
                slidesPerView: 1.2,
                spaceBetween: 16,
                loop: {{ count($provinces) > 3 ? 'true' : 'false' }},
                grabCursor: true,
                navigation: {
                    nextEl: '#province-next',
                    prevEl: '#province-prev',
                },
                pagination: {
                    el: '.province-pagination',
                    clickable: true,
                },
                breakpoints: {
                    480:  { slidesPerView: 1.8, spaceBetween: 20 },
                    640:  { slidesPerView: 2.2, spaceBetween: 20 },
                    768:  { slidesPerView: 2.8, spaceBetween: 24 },
                    1024: { slidesPerView: 3.5, spaceBetween: 24 },
                    1280: { slidesPerView: 4,   spaceBetween: 28 },
                },
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initProvinceSwiper);
        } else {
            initProvinceSwiper();
        }
    })();
    </script>
    @endif
</div>
