<div>
    @if (!empty($vouchers))
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
                        @foreach ($vouchers as $voucher)
                        <div class="swiper-slide">
                            <div style="height:100%; min-height:260px; border-radius:18px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.12); background:{{ $voucher['bg'] }}; display:flex; align-items:stretch; justify-content:space-between; padding:20px; gap:12px; position:relative;">

                                {{-- Nội dung bên trái --}}
                                <div style="flex:1; min-width:0; display:flex; flex-direction:column; justify-content:space-between; color:#fff;">
                                    <div>
                                        {{-- Logo --}}
                                        <div style="display:inline-flex; align-items:center; background:#fff; border-radius:999px; padding:5px 12px 5px 8px; margin-bottom:12px;">
                                            <img src="{{ asset('/storage/'.$logo) }}" alt="Logo" style="height:16px; width:auto; object-fit:contain;">
                                        </div>

                                        <p style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; opacity:.9; margin:0 0 3px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;">{{ $voucher['label'] }}</p>
                                        <p style="font-size:12px; opacity:.75; margin:0 0 10px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;">{{ $voucher['timeRange'] }}</p>

                                        <h3 style="font-size:24px; font-weight:800; line-height:1.15; margin:0 0 4px; overflow:hidden; display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;">{{ $voucher['title'] }}</h3>
                                        <p style="font-size:13px; font-weight:600; opacity:.9; margin:0; line-height:1.4; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; min-height:calc(1.4em * 2);">{{ $voucher['description'] }}</p>
                                    </div>

                                    <div style="margin-top:14px;">
                                        <div style="display:inline-block; border:1.5px dashed rgba(255,255,255,.6); border-radius:10px; padding:6px 14px; margin-bottom:8px;">
                                            <span style="font-size:13px; font-weight:800; letter-spacing:.08em;">{{ $voucher['code'] }}</span>
                                        </div>
                                        <p style="font-size:11px; opacity:.65; margin:0; line-height:1.4; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">{{ $voucher['note'] }}</p>
                                    </div>
                                </div>

                                {{-- Ảnh minh hoạ bên phải --}}
                                <div style="flex-shrink:0; width:88px; display:flex; align-items:center; justify-content:center;">
                                    <div style="width:88px; height:88px; border-radius:50%; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:42px; line-height:1;">
                                        {{ $voucher['emoji'] }}
                                    </div>
                                </div>
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
