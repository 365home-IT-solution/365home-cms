<div>
    @if (!empty($images))
    <section class="py-10 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">

            {{-- Header --}}
            {{-- <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2 bg-teal-600 text-white px-4 py-2 rounded-full shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    <span class="font-extrabold text-sm tracking-wider uppercase">Voucher & Ưu Đãi</span>
                </div>
            </div> --}}

            {{-- Swiper carousel --}}
            <div style="position:relative;">

                <button id="voucher-prev"
                    style="position:absolute; left:-14px; top:50%; transform:translateY(-50%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.1); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>

                <button id="voucher-next"
                    style="position:absolute; right:-14px; top:50%; transform:translateY(-50%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.1); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>

                <div class="swiper voucher-swiper" style="overflow:hidden;">
                    <div class="swiper-wrapper" style="align-items:stretch;">
                        @foreach ($images as $imageUrl)
                        <div class="swiper-slide">
                            <div style="border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.1); line-height:0;">
                                <img src="{{ $imageUrl }}"
                                    alt="Voucher ưu đãi"
                                    loading="lazy"
                                    style="width:100%; height:auto; display:block; object-fit:cover;" />
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>

            </div>
        </div>
    </section>

    <script>
    (function () {
        function initVoucherSwiper() {
            if (typeof Swiper === 'undefined') {
                setTimeout(initVoucherSwiper, 200);
                return;
            }
            var el = document.querySelector('.voucher-swiper');
            if (!el || el.swiper) return;
            new Swiper('.voucher-swiper', {
                slidesPerView: 1.2,
                spaceBetween: 16,
                grabCursor: true,
                speed: 500,
                navigation: {
                    prevEl: '#voucher-prev',
                    nextEl: '#voucher-next',
                },
                breakpoints: {
                    640:  { slidesPerView: 2,   spaceBetween: 16 },
                    1024: { slidesPerView: 3,   spaceBetween: 20 },
                },
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initVoucherSwiper);
        } else {
            initVoucherSwiper();
        }
        document.addEventListener('livewire:navigated', function () {
            var el = document.querySelector('.voucher-swiper');
            if (el && el.swiper) { el.swiper.destroy(true, true); }
            initVoucherSwiper();
        });
    })();
    </script>
    @endif
</div>
