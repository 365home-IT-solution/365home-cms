@if($headerRow)
<div class="w-full">
    @include('bladethemev1::livewire.hero-section._script')

    @include('bladethemev1::livewire.hero-section._styles')

    @php
        $formClass = 'py-2';

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

    @include('bladethemev1::livewire.hero-section._banner-form')

    <template x-teleport="#header-search-slot">
        @include('bladethemev1::livewire.hero-section._header-compact-pill')
    </template>
</div>
@else
<div
    x-data="{ heroShrunk: window.__heroAlwaysCompact || false, compactTop: '0px', formOpen: false, menuOpen: false }"
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
            // Header (#main-header-bar) tự lo việc sticky/hiển thị của chính nó rồi (position:sticky cố định).
            // hero-section chỉ còn theo dõi scroll để quyết định lúc nào hiện thanh tìm kiếm compact-pill (mobile).
            const hdr = document.getElementById('main-header-bar');
            if (hdr) compactTop = hdr.offsetHeight + 'px';
            let _heroH = 0;
            $nextTick(() => {
                const s = $el.querySelector('section');
                if (s) _heroH = s.offsetHeight;
            });
            const _onScroll = () => {
                if (!$el.isConnected) {
                    window.removeEventListener('scroll', _onScroll);
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
            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('scroll', _onScroll);
            }, { once: true });
            $watch('heroShrunk', () => {
                compactTop = document.getElementById('main-header-bar')?.offsetHeight + 'px' || '0px';
            });
            $watch('formOpen', val => {
                if (!heroShrunk) return;
                setTimeout(() => {
                    const h = document.getElementById('main-header-bar');
                    compactTop = (h && h.offsetHeight > 0) ? h.offsetHeight + 'px' : '0px';
                }, 30);
            });
        }
        document.addEventListener('livewire:navigated', () => { heroShrunk = window.__heroAlwaysCompact || false; });
    ">
    @include('bladethemev1::livewire.hero-section._script')

    @include('bladethemev1::livewire.hero-section._styles')

    @php
        $labelClass   = 'text-gray-500';
        $sectionClass = 'bg-white border-b border-gray-100 shadow-sm';
        $contentPad   = 'pb-6 pt-6';
        $formClass    = 'p-4 md:p-6';

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

    <section @if($noBanner) x-show="!heroShrunk" x-cloak @endif class="relative flex flex-col justify-end lg:hidden {{ $sectionClass }}">

        <div class="relative z-10 w-full max-w-11xl mx-auto px-4 sm:px-6 {{ $contentPad }}">

            @include('bladethemev1::livewire.hero-section._banner-form')
        </div>

    </section>


    {{-- Compact bar --}}
    <div x-show="heroShrunk"
         x-cloak
         class="lg:hidden"
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
@endif