<div
    x-data="{ heroShrunk: window.__heroAlwaysCompact || false, compactTop: '0px', formOpen: false, menuOpen: false, pillHeight: 0 }"
    x-init="
        if (window.__heroAlwaysCompact) {
            heroShrunk = true;
            compactTop = '0px';
            $watch('formOpen', val => {
                window.dispatchEvent(new CustomEvent(val ? 'hero-form-open' : 'hero-form-close'));
                if (val) {
                    setTimeout(() => {
                        const hdr = document.getElementById('main-header-bar');
                        if (hdr && hdr.offsetHeight > 0) compactTop = hdr.offsetHeight + 'px';
                    }, 30);
                } else {
                    compactTop = '0px';
                }
            });
        } else {
            const hdr = document.getElementById('main-header-bar');
            if (hdr) compactTop = hdr.offsetHeight + 'px';
            let _heroH = 0;
            $nextTick(() => {
                const s = $el.querySelector('section');
                if (s) _heroH = s.offsetHeight;
            });
            const _hideHeader = () => {
                const h = document.getElementById('main-header-bar');
                if (!h) return;
                h.style.transition = 'transform 220ms ease-in-out, opacity 220ms ease-in-out';
                h.style.transform = 'translateY(-110%)';
                h.style.opacity = '0';
                h.style.pointerEvents = 'none';
            };
            const _showHeader = () => {
                const h = document.getElementById('main-header-bar');
                if (!h) return;
                h.style.transition = 'transform 220ms ease-in-out, opacity 220ms ease-in-out';
                h.style.transform = '';
                h.style.opacity = '';
                h.style.pointerEvents = '';
            };
            const _onScroll = () => {
                // Tự huỷ nếu hero-section không còn nằm trong trang (VD: đã chuyển trang qua wire:navigate
                // nhưng listener cũ vẫn còn sống) — tránh ẩn nhầm header ở trang không có hero-section.
                if (!$el.isConnected) {
                    window.removeEventListener('scroll', _onScroll);
                    _showHeader();
                    return;
                }
                const threshold = window.__heroScrollThreshold ?? (_heroH > 0 ? _heroH - 80 : 300);
                const shrunk = window.scrollY > threshold;
                if (shrunk !== heroShrunk) {
                    heroShrunk = shrunk;
                    window.dispatchEvent(new CustomEvent(shrunk ? 'hero-shrunk' : 'hero-expanded'));
                }
            };
            window.addEventListener('scroll', _onScroll, { passive: true });
            // Gỡ listener + trả header về trạng thái bình thường khi rời trang qua wire:navigate,
            // tránh listener của trang này còn sống và ẩn header ở trang kế tiếp không có hero-section.
            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('scroll', _onScroll);
                _showHeader();
            }, { once: true });
            $watch('heroShrunk', val => {
                if (val) { _hideHeader(); compactTop = '0px'; }
                else { _showHeader(); compactTop = document.getElementById('main-header-bar')?.offsetHeight + 'px' || '0px'; }
            });
            $watch('formOpen', val => {
                if (!heroShrunk) return;
                if (val) {
                    _showHeader();
                    setTimeout(() => {
                        const h = document.getElementById('main-header-bar');
                        compactTop = (h && h.offsetHeight > 0) ? h.offsetHeight + 'px' : '0px';
                    }, 30);
                } else {
                    _hideHeader();
                    compactTop = '0px';
                }
            });
        }
        {{-- Mobile: form thu gọn mở dạng popup toàn màn hình -> khoá scroll nền trong lúc mở --}}
        $watch('formOpen', val => {
            if (window.matchMedia('(max-width: 767px)').matches) {
                document.documentElement.style.overflow = val ? 'hidden' : '';
                document.body.style.overflow = val ? 'hidden' : '';
            }
        });
        {{-- Đo chiều cao pill thu gọn để chèn khoảng đệm tương ứng (mobile), tránh nội dung
             bên dưới bị pill (position:fixed) đè lên. --}}
        const _measurePill = () => {
            const pill = $refs.compactPillEl;
            if (pill && pill.offsetHeight > 0) pillHeight = pill.offsetHeight;
        };
        $nextTick(_measurePill);
        window.addEventListener('resize', _measurePill, { passive: true });
        document.addEventListener('livewire:navigated', () => {
            heroShrunk = window.__heroAlwaysCompact || false;
            $nextTick(_measurePill);
        });
    ">
    @include('bladethemev1::livewire.hero-section._script')

    @include('bladethemev1::livewire.hero-section._styles')

    @php
        $labelClass   = $noBanner ? 'text-gray-500'  : 'text-white/80';
        $tabInactive  = $noBanner
            ? 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200 hover:text-gray-800'
            : 'bg-white/10 text-white/80 border-white/25 hover:bg-white/20 hover:text-white';
        $sectionClass = $noBanner ? 'bg-white border-b border-gray-100 shadow-sm' : '';
        $contentPad   = $noBanner ? 'pb-6 pt-6' : 'pb-16 pt-28';
        $formClass    = $noBanner ? 'p-4 md:p-6' : 'rounded-2xl shadow-2xl p-6 md:p-8';

        $locationLabel = $selectedLocation
            ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm')
            : 'Chọn địa điểm';
        $buoiLabel = match ($selectedBuoi) {
            '1' => 'Theo giờ',
            '2' => 'Theo ngày',
            default => 'Tất cả',
        };
        $guestsLabel = match ($selectedGuests) {
            '1', '2', '3', '4' => $selectedGuests . ' người',
            '5' => '5+ người',
            default => 'Số người',
        };

        $checkmarkSvg = '<svg class="w-4 h-4 text-teal-600 ml-auto shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    @endphp

    {{-- Mobile: mọi trang đều dùng thanh tìm kiếm thu gọn (compact bar) ở trên cùng thay vì
         banner/thanh tìm kiếm rộng như desktop. Bấm vào compact bar sẽ mở popup toàn màn hình. --}}
    <style>
        @media (max-width: 767px) {
            .hero-full-bar-section { display: none !important; }
            .hero-compact-bar-wrap { display: block !important; }
            .hero-compact-bar-spacer { display: block !important; }
            {{-- Header thật (logo + menu ngang) không còn cần thiết trên mobile — compact bar đã có
                 logo + nút menu riêng, và popup tìm kiếm có thanh tiêu đề + nút đóng riêng. Ẩn hẳn
                 header thật trên mobile để tránh đè lên nút đóng popup / trùng lặp với compact bar. --}}
            #main-header-bar { display: none !important; }
        }
    </style>

    <section @if($noBanner) x-show="!heroShrunk" x-cloak @endif class="hero-full-bar-section relative flex flex-col justify-end {{ $sectionClass }}">

        @unless($noBanner)
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center bg-no-repeat"
                style="background-image: url('https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1920&q=80')">
            </div>
            <div class="absolute inset-0"
                style="background: linear-gradient(to bottom, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0.15) 40%, rgba(0,0,0,0.60) 100%)">
            </div>
        </div>
        @endunless

        <div class="relative z-10 w-full max-w-7xl mx-auto px-4 sm:px-6 {{ $contentPad }}">

            @include('bladethemev1::livewire.hero-section._banner-form')
        </div>

        @unless($noBanner)
        <div class="absolute bottom-0 left-0 right-0">
            <svg viewBox="0 0 1440 80" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"
                class="w-full h-16">
                <path d="M0 80L1440 80L1440 20C1200 80 960 0 720 20C480 40 240 0 0 20L0 80Z" fill="white"/>
            </svg>
        </div>
        @endunless
    </section>

    {{-- Khoảng đệm giữ chỗ cho pill thu gọn (position:fixed) trên mobile — chỉ cần cho các trang
         có banner lớn (không phải noBanner), vì các trang noBanner đã tự chừa khoảng trống riêng. --}}
    @unless($noBanner)
        <div class="hero-compact-bar-spacer" :style="{ height: pillHeight + 'px' }" style="display:none;"></div>
    @endunless

    {{-- Compact bar --}}
    <div x-show="heroShrunk"
         x-cloak
         class="hero-compact-bar-wrap"
         :style="{ position: 'fixed', top: compactTop, left: 0, right: 0, zIndex: 1100}"
         style="display:none;"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-180"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         @click.outside="formOpen = false">

        @include('bladethemev1::livewire.hero-section._compact-pill')

        @include('bladethemev1::livewire.hero-section._compact-form')
    </div>

    @include('bladethemev1::livewire.hero-section._menu-drawer')
</div>