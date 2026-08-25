@if($mobileHeaderModal)
{{-- Component Livewire độc lập, đặt trực tiếp (không teleport) trong khối mobile của header.
     KHÔNG dùng x-teleport ở đây: wire:click bên trong nội dung bị teleport sang DOM của MỘT
     component Livewire khác (header) sẽ gọi nhầm sang component đó thay vì hero-section này,
     khiến setLocation()/setBuoi()/setGuests() không chạy. Nên phải là 1 Livewire component
     riêng, nằm đúng vị trí tự nhiên, để mọi wire:click luôn trỏ đúng vào component này. --}}
<div class="w-full">
    @include('bladethemev1::livewire.hero-section._script')

    @include('bladethemev1::livewire.hero-section._styles')

    @php
        $locationLabel = $selectedLocation
            ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm')
            : 'Chọn địa điểm';
        $guestsLabel = match ($selectedGuests) {
            '1', '2', '3', '4' => $selectedGuests . ' người',
            '5' => '5+ người',
            default => 'Thêm khách',
        };
        $guestOpts = ['' => 'Tất cả', '1' => '1 người', '2' => '2 người', '3' => '3 người', '4' => '4 người', '5' => '5+ người'];

        $checkmarkSvg = '<svg class="w-4 h-4 text-teal-600 ml-auto shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    @endphp

    {{-- Lồng x-data giống hệt _banner-form.blade.php (heroDatePicker() ở ngoài, các state
         riêng cho mobile-steps ở trong) — KHÔNG dùng spread {...heroDatePicker()} vì spread
         sẽ gọi ngay các getter (viewMonthName, displayCheckIn, ...) và đóng băng thành giá trị
         tĩnh, làm mất reactivity. --}}
    <div x-data="heroDatePicker({{ $selectedBuoi === '2' ? 'true' : 'false' }})">
        <div x-data="{
                mobileSearchOpen: false,
                locOpen: false, guestsOpen: false, mobileStep: 1, locating: false,
                locationSearch: '', selectedLocationSlug: '{{ $selectedLocation }}', mobileLocations: @js($locations),
                selectedGuestsVal: '{{ $selectedGuests }}',
                guestOptions: { '': 'Tất cả', '1': '1 người', '2': '2 người', '3': '3 người', '4': '4 người', '5': '5+ người' },
                get locationLabel() {
                    if (!this.selectedLocationSlug) return 'Chọn địa điểm';
                    const loc = this.mobileLocations.find(l => l.slug === this.selectedLocationSlug);
                    return loc ? loc.name : 'Chọn địa điểm';
                },
                get guestsLabel() { return this.guestOptions[this.selectedGuestsVal] || 'Thêm khách'; },
                init() {
                    this.syncLocationFromUrl();
                    this.autoFillLocation();
                    this.syncGuestsFromUrl();
                    window.addEventListener('province-selected', () => this.autoFillLocation());
                },
                // Xem giải thích chi tiết ở _banner-form.blade.php.
                syncGuestsFromUrl() {
                    if (this.selectedGuestsVal) return;
                    const adults = window.parseGuestsFromUrl ? window.parseGuestsFromUrl() : '';
                    if (adults && this.guestOptions[adults] !== undefined) this.selectedGuestsVal = adults;
                },
                // Xem giải thích chi tiết ở _banner-form.blade.php.
                syncLocationFromUrl() {
                    if (this.selectedLocationSlug) return;
                    const slug = window.parseLocationSlugFromUrl ? window.parseLocationSlugFromUrl() : '';
                    if (!slug) return;
                    const loc = this.mobileLocations.find(l => l.slug === slug);
                    if (loc) this.selectedLocationSlug = loc.slug;
                },
                // Khách đã đăng nhập và có province_id trên tài khoản: xem giải thích chi tiết ở
                // _banner-form.blade.php.
                autoFillLocation() {
                    if (this.selectedLocationSlug) return;
                    if (!localStorage.getItem('auth_token')) return;
                    const provinceId = localStorage.getItem('home_province_id');
                    if (!provinceId) return;
                    const loc = this.mobileLocations.find(l => String(l.id) === String(provinceId));
                    if (loc) this.selectedLocationSlug = loc.slug;
                },
                locateMe() {
                    this.locating = true;
                    window.heroLocateNearest(
                        (slug) => {},
                        @js($locations),
                        (loc) => { this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug; this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep); },
                        (msg) => { this.locating = false; alert(msg); }
                    );
                }
            }">
        @include('bladethemev1::livewire.hero-section._header-compact-pill-mobile')

        {{-- Modal toàn màn hình khi bấm vào thanh tìm kiếm trên mobile, mượt như Airbnb --}}
        <div x-show="mobileSearchOpen" x-cloak
             x-transition:enter="transition ease-out duration-250"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-[2000] bg-white overflow-y-auto"
             style="-webkit-overflow-scrolling: touch;"
             @keydown.escape.window="mobileSearchOpen = false">

            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-4 py-3.5">
                <span class="text-base font-bold text-gray-900">Tìm kiếm</span>
                <button type="button" @click="mobileSearchOpen = false"
                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition-colors hover:bg-gray-100">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="px-4 pt-3">
                @include('bladethemev1::livewire.hero-section._mobile-steps', ['boxSuffix' => 'HeaderModal', 'searchAction' => 'mobileSearchOpen = false; submitSearch()'])
            </div>
        </div>
        </div>
    </div>
</div>
@elseif($headerRow)
{{-- QUAN TRỌNG: phải có comment/dòng trống giữa @elseif và <div> bên dưới. Livewire chèn
     marker <!--[if BLOCK]--> ngay trước tag gốc; nếu marker đó dính liền <div> (không có
     ký tự xuống dòng ở giữa), regex xác định "root tag" của Livewire (Utils::insertAttributesIntoHtmlRoot,
     yêu cầu \n ngay trước tag) sẽ bỏ qua <div> này và gắn nhầm wire:id vào <script> bên trong
     (từ _script.blade.php) — khiến wire:click ở component này bị submit lên component cha (header)
     thay vì hero-section, gây lỗi "Public method ... not found on component". --}}
<div class="w-full">
    @include('bladethemev1::livewire.hero-section._script')

    @include('bladethemev1::livewire.hero-section._styles')

    @php
        $formClass = 'py-2';

        $locationLabel = $selectedLocation
            ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm')
            : 'Chọn địa điểm';
        $guestsLabel = match ($selectedGuests) {
            '1', '2', '3', '4' => $selectedGuests . ' người',
            '5' => '5+ người',
            default => 'Thêm khách',
        };

        $checkmarkSvg = '<svg class="w-4 h-4 text-teal-600 ml-auto shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    @endphp

    @include('bladethemev1::livewire.hero-section._banner-form')

    @unless($skipHeaderPill)
        <template x-teleport="#header-search-slot">
            @include('bladethemev1::livewire.hero-section._header-compact-pill')
        </template>
    @endunless
</div>
@else
{{-- Xem giải thích ở nhánh @elseif($headerRow) phía trên: bắt buộc phải có comment/dòng trống
     ngay sau @else để tránh marker <!--[if BLOCK]--> của Livewire dính liền <div>, làm regex
     nhận diện root tag gắn nhầm wire:id vào phần tử con bên trong thay vì <div> này. --}}
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
        $locationLabel = $selectedLocation
            ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm')
            : 'Chọn địa điểm';
        $guestsLabel = match ($selectedGuests) {
            '1', '2', '3', '4' => $selectedGuests . ' người',
            '5' => '5+ người',
            default => 'Thêm khách',
        };

        $checkmarkSvg = '<svg class="w-4 h-4 text-teal-600 ml-auto shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    @endphp

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