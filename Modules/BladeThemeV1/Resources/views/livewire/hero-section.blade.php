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
        document.addEventListener('livewire:navigated', () => { heroShrunk = window.__heroAlwaysCompact || false; });
    ">
    <script>
        window.heroDatePicker = function() {
            return {
                open: false,
                checkIn: null,
                checkOut: null,
                hoverDate: null,
                checkInHour: 14,
                checkInMin: 0,
                checkOutHour: 12,
                checkOutMin: 0,
                checkInHourOpen: false,
                checkOutHourOpen: false,
                checkInHourPos: { openUp: true, top: 0, bottom: 0, left: 0, width: 0 },
                checkOutHourPos: { openUp: true, top: 0, bottom: 0, left: 0, width: 0 },
                _hourDropdownCleanup: null,
                computeHourDropdownPos(boxEl) {
                    const r = boxEl.getBoundingClientRect();
                    const panelH = 180; // ước lượng chiều cao dropdown (max-height panel)
                    const openUp = r.top - 6 >= panelH || (window.innerHeight - r.bottom - 6) < panelH;
                    return openUp
                        ? { openUp: true, bottom: window.innerHeight - r.top + 6, top: 0, left: r.left, width: Math.max(r.width, 160) }
                        : { openUp: false, top: r.bottom + 6, bottom: 0, left: r.left, width: Math.max(r.width, 160) };
                },
                openHourDropdown(which, boxEl) {
                    if (this._hourDropdownCleanup) { this._hourDropdownCleanup(); this._hourDropdownCleanup = null; }

                    const otherKey = which === 'checkIn' ? 'checkOutHourOpen' : 'checkInHourOpen';
                    const openKey = which === 'checkIn' ? 'checkInHourOpen' : 'checkOutHourOpen';
                    const posKey = which === 'checkIn' ? 'checkInHourPos' : 'checkOutHourPos';

                    this[otherKey] = false;
                    const willOpen = !this[openKey];
                    this[openKey] = willOpen;

                    if (!willOpen) return;

                    const update = () => { this[posKey] = this.computeHourDropdownPos(boxEl); };
                    update();

                    // scroll không nổi bọt (bubble) nên phải bắt ở pha capture để nghe được cả scroll trong khung con
                    window.addEventListener('scroll', update, true);
                    window.addEventListener('resize', update);
                    this._hourDropdownCleanup = () => {
                        window.removeEventListener('scroll', update, true);
                        window.removeEventListener('resize', update);
                    };
                    this.$watch(openKey, val => {
                        if (!val && this._hourDropdownCleanup) {
                            this._hourDropdownCleanup();
                            this._hourDropdownCleanup = null;
                        }
                    });
                },
                viewMonth: null,
                viewYear: null,
                openField: null,
                monthNames: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
                    'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'
                ],
                hours: Array.from({ length: 24 }, (_, i) => i),
                minutes: [0, 15, 30, 45],
                init() {
                    const now = new Date();
                    this.viewMonth = now.getMonth();
                    this.viewYear = now.getFullYear();
                    const correct = () => this.$nextTick(() => this.ensureCheckOutAfterCheckIn());
                    this.$watch('checkIn', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkInHour', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkInMin', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkOut', correct);
                    this.$watch('checkOutHour', correct);
                },
                get viewMonthName() { return this.monthNames[this.viewMonth]; },
                get nextViewMonth() { return this.viewMonth === 11 ? 0 : this.viewMonth + 1; },
                get nextViewYear() { return this.viewMonth === 11 ? this.viewYear + 1 : this.viewYear; },
                get nextViewMonthName() { return this.monthNames[this.nextViewMonth]; },
                prevMonth() {
                    if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; }
                    else { this.viewMonth--; }
                },
                nextMonth() {
                    if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; }
                    else { this.viewMonth++; }
                },
                getDaysInMonth(year, month) { return new Date(year, month + 1, 0).getDate(); },
                getFirstDay(year, month) { return new Date(year, month, 1).getDay(); },
                getCalendarDays(year, month) {
                    const days = [];
                    const firstDay = this.getFirstDay(year, month);
                    for (let i = 0; i < firstDay; i++) days.push(null);
                    for (let d = 1; d <= this.getDaysInMonth(year, month); d++) days.push(new Date(year, month, d));
                    return days;
                },
                selectDate(date) {
                    if (!date || this.isPast(date)) return;
                    if (!this.checkIn) {
                        this.checkIn = date;
                        this.checkOut = date;
                    } else if (this.checkIn && this.checkOut && !this.isSameDay(this.checkIn, this.checkOut)) {
                        this.checkIn = date;
                        this.checkOut = date;
                    } else {
                        if (this.isSameDay(date, this.checkIn)) {
                            // giữ nguyên
                        } else if (date < this.checkIn) {
                            this.checkOut = this.checkIn;
                            this.checkIn = date;
                        } else {
                            this.checkOut = date;
                        }
                    }
                    this.ensureCheckOutAfterCheckIn();
                },
                isPast(date) {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    return date < today;
                },
                isSameDay(a, b) {
                    return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
                },
                isSelected(date) { return date && (this.isSameDay(date, this.checkIn) || this.isSameDay(date, this.checkOut)); },
                isRangeStart(date) { return date && this.isSameDay(date, this.checkIn); },
                isRangeEnd(date) { return date && this.isSameDay(date, this.checkOut); },
                isInRange(date) {
                    if (!date || !this.checkIn) return false;
                    const end = this.checkOut || this.hoverDate;
                    if (!end) return false;
                    return date > this.checkIn && date < end;
                },
                formatDisplay(date) {
                    if (!date) return '';
                    return `${String(date.getDate()).padStart(2,'0')}/${String(date.getMonth()+1).padStart(2,'0')}/${date.getFullYear()}`;
                },
                get availableCheckoutHours() {
                    if (!this.isSameDayBooking) return this.hours;
                    return this.hours.filter(h => {
                        if (h > this.checkInHour) return true;
                        if (h === this.checkInHour) return this.minutes.some(m => m > this.checkInMin);
                        return false;
                    });
                },
                get availableCheckoutMinutes() {
                    if (!this.isSameDayBooking || this.checkOutHour !== this.checkInHour) return this.minutes;
                    return this.minutes.filter(m => m > this.checkInMin);
                },
                get isCheckInToday() {
                    return !!(this.checkIn && this.isSameDay(this.checkIn, new Date()));
                },
                get minCheckInHour() {
                    return this.isCheckInToday ? new Date().getHours() : 0;
                },
                get availableCheckInHours() {
                    if (!this.isCheckInToday) return this.hours;
                    const now = new Date();
                    return this.hours.filter(h => {
                        if (h > now.getHours()) return true;
                        if (h === now.getHours()) return this.minutes.some(m => m > now.getMinutes());
                        return false;
                    });
                },
                get availableCheckInMinutes() {
                    if (!this.isCheckInToday) return this.minutes;
                    const now = new Date();
                    if (this.checkInHour > now.getHours()) return this.minutes;
                    if (this.checkInHour === now.getHours()) return this.minutes.filter(m => m > now.getMinutes());
                    return [];
                },
                ensureCheckInNotPast() {
                    if (!this.isCheckInToday) return;
                    const now = new Date();
                    const nowH = now.getHours(), nowM = now.getMinutes();
                    if (this.checkInHour > nowH) return;
                    if (this.checkInHour === nowH) {
                        if (this.minutes.some(m => m > nowM && m === this.checkInMin)) return;
                    }
                    const nextH = this.availableCheckInHours[0];
                    if (nextH === undefined) return;
                    this.checkInHour = nextH;
                    const validMins = nextH === nowH ? this.minutes.filter(m => m > nowM) : this.minutes;
                    this.checkInMin = validMins[0] ?? 0;
                },
                get displayCheckIn() {
                    if (!this.checkIn) return '';
                    return `${this.formatDisplay(this.checkIn)} ${String(this.checkInHour).padStart(2,'0')}:${String(this.checkInMin).padStart(2,'0')}`;
                },
                get displayCheckOut() {
                    const date = this.checkOut || this.checkIn;
                    if (!date) return '';
                    return `${this.formatDisplay(date)} ${String(this.checkOutHour).padStart(2,'0')}:${String(this.checkOutMin).padStart(2,'0')}`;
                },
                get isSameDayBooking() {
                    return !!(this.checkIn && this.checkOut && this.isSameDay(this.checkIn, this.checkOut));
                },
                ensureCheckOutAfterCheckIn(forceOutHour) {
                    if (!this.isSameDayBooking) return;
                    const inH = this.checkInHour, inM = this.checkInMin;
                    const outH = (forceOutHour !== undefined) ? Number(forceOutHour) : this.checkOutHour;
                    const outM = this.checkOutMin;
                    let newH = outH, newM = outM;
                    if (outH < inH) {
                        newH = inH;
                        const nxt = this.minutes.find(m => m > inM);
                        if (nxt !== undefined) { newM = nxt; } else { newH = Math.min(inH + 1, 23); newM = 0; }
                    } else if (outH === inH && outM <= inM) {
                        const nxt = this.minutes.find(m => m > inM);
                        if (nxt !== undefined) { newM = nxt; } else { newH = Math.min(inH + 1, 23); newM = 0; }
                    }
                    if (newH !== this.checkOutHour) this.checkOutHour = newH;
                    if (newM !== this.checkOutMin) this.checkOutMin = newM;
                },
                async confirm() {
                    if (this.checkIn) {
                        await this.$wire.set('checkIn', this.displayCheckIn);
                        await this.$wire.set('checkOut', this.displayCheckOut);
                    }
                    this.open = false;
                },
                async submitSearch() {
                    if (this.checkIn) {
                        await this.$wire.set('checkIn', this.displayCheckIn);
                        await this.$wire.set('checkOut', this.displayCheckOut);
                    }
                    await this.$wire.search();
                },
                cancel() { this.open = false; },
            };
        };
    </script>

    <style>
        .menu-drawer {
            display: flex;
            flex-direction: column;
        }
    </style>

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

    <section @if($noBanner) x-show="!heroShrunk" x-cloak @endif class="relative flex flex-col justify-end {{ $sectionClass }}">

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

            <div x-data="heroDatePicker()" class="{{ $formClass }}">

                {{-- Room type tabs --}}
                <div class="mb-6">
                    <div class="flex flex-wrap gap-2">
                        <button
                        type="button"
                        wire:click.stop="setRoomType('all')"
                            :class="'{{ $selectedRoomType }}' === 'all'
                                ? 'bg-teal-800 text-white border-teal-800/60 shadow-md'
                                : '{{ $tabInactive }}'"
                            class="px-5 py-2 rounded-full text-sm font-semibold border backdrop-blur-sm transition-all duration-200">
                            Tất cả
                        </button>
                        @foreach ($roomTypes as $type)
                            <button type="button" wire:click.stop="setRoomType(@js($type['slug']))"
                                wire:key="room-type-{{ $type['slug'] }}"
                                :class="'{{ $selectedRoomType }}' === '{{ $type['slug'] }}'
                                    ? 'bg-teal-800 text-white border-teal-800/60 shadow-md'
                                    : '{{ $tabInactive }}'"
                                class="px-5 py-2 rounded-full text-sm font-semibold border backdrop-blur-sm transition-all duration-200">
                                {{ strtoupper($type['name']) }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Search bar — Airbnb style --}}
                <div data-bar
                    x-data="{ locOpen: false, buoiOpen: false, guestsOpen: false }"
                    @click.outside="locOpen = false; buoiOpen = false; guestsOpen = false"
                    class="relative flex items-stretch rounded-2xl transition-all duration-300"
                    :class="open ? 'bg-gray-100 shadow-2xl' : 'bg-white border border-gray-200 shadow-lg'"
                    style="overflow:visible;">

                    {{-- Highlight trượt theo field đang chọn (đo pixel thật của field, không dùng % cố định) --}}
                    <div class="absolute rounded-xl bg-gray-200 pointer-events-none transition-all duration-300 ease-out" style="top:8px;bottom:8px;z-index:0;"
                        x-effect="
                            const active = locOpen ? 'loc' : open ? 'date' : buoiOpen ? 'buoi' : guestsOpen ? 'guests' : null;
                            if (!active) { $el.style.opacity = 0; }
                            else {
                                const target = $el.parentElement.querySelector('[data-field=' + active + ']');
                                if (target) {
                                    $el.style.opacity = 1;
                                    $el.style.left = target.offsetLeft + 'px';
                                    $el.style.width = target.offsetWidth + 'px';
                                }
                            }
                        "></div>

                    {{-- Địa điểm --}}
                    <div data-field="loc" class="relative z-10 flex-[3] min-w-0">
                        <button type="button" @click="locOpen = !locOpen; buoiOpen = false; guestsOpen = false"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-l-2xl transition-colors">
                            <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Địa điểm</span>
                            <span class="text-sm font-semibold mt-0.5 truncate {{ $selectedLocation ? 'text-gray-900' : 'text-gray-400' }}">{{ $locationLabel }}</span>
                        </button>
                        <div x-show="locOpen" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] left-0 w-72 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                            <button type="button" wire:click.stop="setLocation('')" @click="locOpen = false"
                                wire:key="location-all"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ !$selectedLocation ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                <span>Tất cả địa điểm</span>
                                @if(!$selectedLocation) {!! $checkmarkSvg !!} @endif
                            </button>
                            @foreach($locations as $loc)
                            <button type="button" wire:click.stop="setLocation(@js($loc['slug']))" @click="locOpen = false"
                                wire:key="location-{{ $loc['slug'] }}"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ $selectedLocation === $loc['slug'] ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                <span>{{ $loc['name'] }}</span>
                                @if($selectedLocation === $loc['slug']) {!! $checkmarkSvg !!} @endif
                            </button>
                            @endforeach
                        </div>
                    </div>


                    {{-- Thời gian --}}
                    <div @click.outside="open = false" data-field="date" class="relative z-10 flex-[4] min-w-0">
                        <button type="button" @click="open=!open"
                            class="w-full h-16 px-5 flex items-center gap-3 rounded-xl transition-colors">
                            <svg class="w-4 h-4 shrink-0 transition-colors duration-200"
                                :class="open ? 'text-teal-600' : 'text-teal-500'"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <div class="flex items-center gap-3 min-w-0 flex-1">
                                <div class="flex flex-col min-w-0">
                                    <span :class="open ? 'text-teal-600' : 'text-gray-500'"
                                        class="text-[10px] font-bold uppercase tracking-widest leading-none transition-colors duration-200">Nhận phòng</span>
                                    <span class="text-sm font-semibold mt-0.5 truncate"
                                        :class="displayCheckIn ? 'text-gray-900' : 'text-gray-400'"
                                        x-text="displayCheckIn || '{{ $checkIn ?: 'Chọn ngày' }}'"></span>
                                </div>
                                <svg class="w-3.5 h-3.5 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                </svg>
                                <div class="flex flex-col min-w-0">
                                    <span :class="open ? 'text-teal-600' : 'text-gray-500'"
                                        class="text-[10px] font-bold uppercase tracking-widest leading-none transition-colors duration-200">Trả phòng</span>
                                    <span class="text-sm font-semibold mt-0.5 truncate"
                                        :class="displayCheckOut ? 'text-gray-900' : 'text-gray-400'"
                                        x-text="displayCheckOut || '{{ $checkOut ?: 'Chọn ngày' }}'"></span>
                                </div>
                            </div>
                        </button>

                        {{-- Date picker dropdown --}}
                        <div x-show="open" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] left-1/2 -translate-x-1/2 w-[640px] bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-y-auto"
                            style="max-height:75vh;">
                    <div class="py-5 px-6">
                        {{-- <div class="flex items-center justify-between mb-5">
                            <h3 class="text-base font-semibold text-gray-800">Chọn ngày &amp; giờ</h3>
                            <button @click="cancel()"
                                class="p-1.5 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div> --}}

                        <div class="flex items-center justify-between mb-4">
                            <button @click="prevMonth()"
                                class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <div class="flex-1 grid grid-cols-2 gap-4 text-center text-sm font-semibold text-gray-700">
                                <span x-text="viewMonthName + ' ' + viewYear"></span>
                                <span x-text="nextViewMonthName + ' ' + nextViewYear"></span>
                            </div>
                            <button @click="nextMonth()"
                                class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <div class="grid grid-cols-7 mb-2">
                                    <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']">
                                        <div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div>
                                    </template>
                                </div>
                                <div class="grid grid-cols-7 gap-y-1">
                                    <template x-for="(date, idx) in getCalendarDays(viewYear, viewMonth)" :key="idx">
                                        <div class="flex items-center justify-center">
                                            <template x-if="date !== null">
                                                <button @click="selectDate(date)" @mouseenter="hoverDate = date" @mouseleave="hoverDate = null"
                                                    :disabled="isPast(date)"
                                                    :class="{
                                                        'bg-teal-800 text-white rounded-full': isSelected(date),
                                                        'bg-teal-50 rounded-none': isInRange(date) && !isSelected(date),
                                                        'rounded-l-full': isRangeStart(date) && checkOut,
                                                        'rounded-r-full': isRangeEnd(date),
                                                        'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                                        'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date),
                                                    }"
                                                    class="w-9 h-9 text-sm font-medium flex items-center justify-center transition-colors"
                                                    x-text="date.getDate()">
                                                </button>
                                            </template>
                                            <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <div class="grid grid-cols-7 mb-2">
                                    <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']">
                                        <div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div>
                                    </template>
                                </div>
                                <div class="grid grid-cols-7 gap-y-1">
                                    <template x-for="(date, idx) in getCalendarDays(nextViewYear, nextViewMonth)" :key="idx">
                                        <div class="flex items-center justify-center">
                                            <template x-if="date !== null">
                                                <button @click="selectDate(date)" @mouseenter="hoverDate = date" @mouseleave="hoverDate = null"
                                                    :disabled="isPast(date)"
                                                    :class="{
                                                        'bg-teal-800 text-white rounded-full': isSelected(date),
                                                        'bg-teal-50 rounded-none': isInRange(date) && !isSelected(date),
                                                        'rounded-l-full': isRangeStart(date) && checkOut,
                                                        'rounded-r-full': isRangeEnd(date),
                                                        'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                                        'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date),
                                                    }"
                                                    class="w-9 h-9 text-sm font-medium flex items-center justify-center transition-colors"
                                                    x-text="date.getDate()">
                                                </button>
                                            </template>
                                            <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Time pickers — +/- spinner --}}
                        <div class="grid grid-cols-2 gap-6 mt-5 pt-5 border-t border-gray-100">
                            {{-- Check-in --}}
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Giờ nhận phòng</p>
                                <div class="flex items-start gap-2">
                                    <div class="flex-1 relative" x-ref="checkInHourBox">
                                        <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Giờ</p>
                                        <div class="flex items-center gap-1.5 border border-gray-200 rounded-xl px-2.5 py-2">
                                            <button @click="checkInHour = Math.max(minCheckInHour, checkInHour - 1)"
                                                :class="checkInHour <= minCheckInHour ? 'opacity-25 cursor-not-allowed' : 'hover:bg-gray-100'"
                                                :disabled="checkInHour <= minCheckInHour"
                                                class="w-6 h-6 rounded-full flex items-center justify-center text-gray-500 font-bold transition-colors select-none">−</button>
                                            <button type="button" @click="openHourDropdown('checkIn', $refs.checkInHourBox)"
                                                class="flex-1 text-center text-sm font-semibold text-gray-900 hover:text-teal-600 transition-colors" x-text="checkInHour + 'h'"></button>
                                            <button @click="checkInHour = Math.min(23, checkInHour + 1)"
                                                class="w-6 h-6 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 font-bold transition-colors select-none">+</button>
                                        </div>
                                    </div>
                                    <template x-teleport="body">
                                        <div x-show="checkInHourOpen" x-cloak
                                            @click.outside="checkInHourOpen = false"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0 -translate-y-1"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            x-transition:leave="transition ease-in duration-100"
                                            x-transition:leave-start="opacity-100 translate-y-0"
                                            x-transition:leave-end="opacity-0 -translate-y-1"
                                            :style="checkInHourPos.openUp ? { position: 'fixed', bottom: checkInHourPos.bottom + 'px', left: checkInHourPos.left + 'px', width: checkInHourPos.width + 'px', zIndex: 9999 } : { position: 'fixed', top: checkInHourPos.top + 'px', left: checkInHourPos.left + 'px', width: checkInHourPos.width + 'px', zIndex: 9999 }"
                                            style="max-height:12rem; overflow-y:auto;"
                                            class="bg-white rounded-xl border border-gray-200 shadow-xl p-1.5 grid grid-cols-4 gap-1">
                                            <template x-for="h in availableCheckInHours" :key="h">
                                                <button type="button" @click.stop="checkInHour = h; checkInHourOpen = false"
                                                    :class="checkInHour === h
                                                        ? 'bg-teal-700 text-white border-teal-700'
                                                        : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                    class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                    x-text="h + 'h'">
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <div class="text-gray-300 text-lg font-light pt-6">:</div>
                                    <div class="flex-1">
                                        <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Phút</p>
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="m in availableCheckInMinutes" :key="m">
                                                <button @click="checkInMin = m"
                                                    :class="checkInMin === m
                                                        ? 'bg-teal-700 text-white border-teal-700'
                                                        : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                    class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                    x-text="String(m).padStart(2,'0')">
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- Check-out --}}
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">
                                    Giờ trả phòng
                                    <span x-show="isSameDayBooking" class="text-teal-600 normal-case font-normal ml-1">(cùng ngày)</span>
                                </p>
                                <div class="flex items-start gap-2">
                                    <div class="flex-1 relative" x-ref="checkOutHourBox">
                                        <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Giờ</p>
                                        <div class="flex items-center gap-1.5 border border-gray-200 rounded-xl px-2.5 py-2">
                                            <button @click="if(checkOutHour > 0){ checkOutHour--; ensureCheckOutAfterCheckIn(); }"
                                                :class="(isSameDayBooking && checkOutHour <= checkInHour) ? 'opacity-25 cursor-not-allowed' : 'hover:bg-gray-100'"
                                                :disabled="isSameDayBooking && checkOutHour <= checkInHour"
                                                class="w-6 h-6 rounded-full flex items-center justify-center text-gray-500 font-bold transition-colors select-none">−</button>
                                            <button type="button" @click="openHourDropdown('checkOut', $refs.checkOutHourBox)"
                                                class="flex-1 text-center text-sm font-semibold text-gray-900 hover:text-teal-600 transition-colors" x-text="checkOutHour + 'h'"></button>
                                            <button @click="if(checkOutHour < 23){ checkOutHour++; ensureCheckOutAfterCheckIn(); }"
                                                class="w-6 h-6 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 font-bold transition-colors select-none">+</button>
                                        </div>
                                    </div>
                                    <template x-teleport="body">
                                        <div x-show="checkOutHourOpen" x-cloak
                                            @click.outside="checkOutHourOpen = false"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0 -translate-y-1"
                                            x-transition:enter-end="opacity-100 translate-y-0"
                                            x-transition:leave="transition ease-in duration-100"
                                            x-transition:leave-start="opacity-100 translate-y-0"
                                            x-transition:leave-end="opacity-0 -translate-y-1"
                                            :style="checkOutHourPos.openUp ? { position: 'fixed', bottom: checkOutHourPos.bottom + 'px', left: checkOutHourPos.left + 'px', width: checkOutHourPos.width + 'px', zIndex: 9999 } : { position: 'fixed', top: checkOutHourPos.top + 'px', left: checkOutHourPos.left + 'px', width: checkOutHourPos.width + 'px', zIndex: 9999 }"
                                            style="max-height:12rem; overflow-y:auto;"
                                            class="bg-white rounded-xl border border-gray-200 shadow-xl p-1.5 grid grid-cols-4 gap-1">
                                            <template x-for="h in availableCheckoutHours" :key="h">
                                                <button type="button" @click.stop="checkOutHour = h; ensureCheckOutAfterCheckIn(); checkOutHourOpen = false"
                                                    :class="checkOutHour === h
                                                        ? 'bg-teal-700 text-white border-teal-700'
                                                        : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                    class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                    x-text="h + 'h'">
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                    <div class="text-gray-300 text-lg font-light pt-6">:</div>
                                    <div class="flex-1">
                                        <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Phút</p>
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="m in (checkIn && checkOut && isSameDay(checkIn, checkOut) && checkOutHour === checkInHour ? minutes.filter(m => m > checkInMin) : minutes)" :key="m">
                                                <button @click="checkOutMin = m; ensureCheckOutAfterCheckIn()"
                                                    :class="checkOutMin === m
                                                        ? 'bg-teal-700 text-white border-teal-700'
                                                        : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                    class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                    x-text="String(m).padStart(2,'0')">
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- <div class="flex items-center justify-between mt-5 pt-4 border-t border-gray-100">
                            <button @click="checkIn = null; checkOut = null"
                                class="text-sm text-gray-500 hover:text-gray-700 underline underline-offset-2 transition-colors">
                                Xóa ngày
                            </button>
                            <div class="flex gap-3">
                                <button @click="cancel()"
                                    class="px-5 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-600 hover:bg-gray-50 transition-colors font-medium">
                                    Hủy
                                </button>
                                <button @click="confirm()"
                                    class="px-5 py-2.5 bg-teal-700 hover:bg-teal-800 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm">
                                    Xác nhận
                                </button>
                            </div>
                        </div> --}}
                    </div>
                        </div>
                    </div>


                    {{-- Loại đặt --}}
                    <div data-field="buoi" class="relative z-10 flex-[2] min-w-0">
                        <button type="button" @click="buoiOpen = !buoiOpen; locOpen = false; guestsOpen = false"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                            <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Loại đặt</span>
                            <span class="text-sm font-semibold mt-0.5 {{ $selectedBuoi ? 'text-gray-900' : 'text-gray-400' }}">{{ $buoiLabel }}</span>
                        </button>
                        <div x-show="buoiOpen" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] left-0 w-48 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                            @php $buoiOpts = ['' => 'Tất cả', '1' => 'Theo giờ', '2' => 'Theo ngày']; @endphp
                            @foreach($buoiOpts as $bVal => $bLbl)
                            <button type="button" wire:click.stop="setBuoi(@js($bVal))" @click="buoiOpen = false"
                                wire:key="buoi-{{ $bVal ?: 'all' }}"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$bVal && !$selectedBuoi) || ($bVal && $selectedBuoi === $bVal) ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                <span>{{ $bLbl }}</span>
                                @if((!$bVal && !$selectedBuoi) || ($bVal && $selectedBuoi === $bVal)) {!! $checkmarkSvg !!} @endif
                            </button>
                            @endforeach
                        </div>
                    </div>


                    {{-- Số người --}}
                    <div data-field="guests" class="relative z-10 flex-[2] min-w-0">
                        <button type="button" @click="guestsOpen = !guestsOpen; locOpen = false; buoiOpen = false"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                            <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Số người</span>
                            <span class="text-sm font-semibold mt-0.5 {{ $selectedGuests ? 'text-gray-900' : 'text-gray-400' }}">{{ $guestsLabel }}</span>
                        </button>
                        <div x-show="guestsOpen" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] right-0 w-52 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                            @php $guestOpts = ['' => 'Tất cả', '1' => '1 người', '2' => '2 người', '3' => '3 người', '4' => '4 người', '5' => '5+ người']; @endphp
                            @foreach($guestOpts as $gVal => $gLbl)
                            <button type="button" wire:click.stop="setGuests(@js($gVal))" @click="guestsOpen = false"
                                wire:key="guests-{{ $gVal ?: 'all' }}"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal) ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                <span>{{ $gLbl }}</span>
                                @if((!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal)) {!! $checkmarkSvg !!} @endif
                            </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Nút tìm kiếm --}}
                    <div class="flex items-center px-3 shrink-0">
                        <button type="button"
                            x-on:click.stop.prevent="submitSearch()"
                            class="w-12 h-12 bg-teal-600 hover:bg-teal-700 text-white rounded-full
                            transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </button>
                    </div>
                </div>

            </div>
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

    {{-- Compact bar --}}
    <div x-show="heroShrunk"
         x-cloak
         :style="{ position: 'fixed', top: compactTop, left: 0, right: 0, zIndex: 1100}"
         style="display:none;"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-180"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         @click.outside="formOpen = false">

        {{-- Pill thu gọn --}}
        <div x-show="!formOpen"
             style="background:#fff; border-bottom:1px solid #f0f0f0; box-shadow:0 2px 12px rgba(0,0,0,.07); padding:9px 64px; position:relative; display:flex; align-items:center;">

            {{-- Logo — về trang chủ --}}
            <a href="{{ url('/') }}"
               style="position:absolute; left:16px; top:50%; transform:translateY(-50%); width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <img src="{{ asset('/storage/'.$logo) }}" alt="Logo" style="max-width:100%; max-height:100%; object-fit:contain;">
            </a>

            <button @click="formOpen = true"
                    style="flex:1; display:flex; align-items:center; gap:0; border:1.5px solid #e5e7eb; border-radius:99px; background:#fff; box-shadow:0 1px 6px rgba(0,0,0,.08); cursor:pointer; overflow:hidden; max-width:52rem; margin:0 auto; transition:box-shadow .2s, border-color .2s;"
                    onmouseover="this.style.boxShadow='0 4px 14px rgba(0,0,0,.12)'; this.style.borderColor='#d1d5db';"
                    onmouseout="this.style.boxShadow='0 1px 6px rgba(0,0,0,.08)'; this.style.borderColor='#e5e7eb';">

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Địa điểm</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; max-width:100%;">
                        {{ $selectedLocation ? collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm' : 'Tìm kiếm địa điểm' }}
                    </span>
                </span>

                <span style="width:1px; height:28px; background:#e5e7eb; flex-shrink:0;"></span>

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Thời gian</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                        {{ $checkIn ? $checkIn . ($checkOut ? ' → ' . $checkOut : '') : 'Thêm ngày' }}
                    </span>
                </span>

                <span style="width:1px; height:28px; background:#e5e7eb; flex-shrink:0;"></span>

                <span style="flex:1; display:flex; flex-direction:column; align-items:flex-start; padding:9px 16px; min-width:0;">
                    <span style="font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; line-height:1;">Loại đặt</span>
                    <span style="font-size:12px; font-weight:600; color:#111827; margin-top:2px;">
                        @if ($selectedBuoi === '1') Theo giờ
                        @elseif ($selectedBuoi === '2') Theo ngày
                        @else Tất cả @endif
                    </span>
                </span>

                <span style="flex-shrink:0; padding:5px 8px 5px 0;">
                    <span style="display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:50%; background:#0f766e;">
                        <svg style="width:15px;height:15px;color:#fff;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </span>
                </span>
            </button>

            {{-- Hamburger --}}
            <button @click.stop="menuOpen = !menuOpen"
                    style="position:absolute; right:16px; top:50%; transform:translateY(-50%); width:42px; height:42px; border-radius:12px; background:white; border:1.5px solid #e5e7eb; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 1px 6px rgba(0,0,0,.08); transition:all .2s; flex-shrink:0;"
                    onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#99f6e4';"
                    onmouseout="this.style.background='white'; this.style.borderColor='#e5e7eb';">
                <svg style="width:18px;height:18px;color:#374151;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>

        {{-- Form mở rộng --}}
        <div x-show="formOpen"
             x-data="heroDatePicker()"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-1"
             style="background:#fff; border-bottom:1px solid #e5e7eb; box-shadow:0 4px 24px rgba(0,0,0,.12);">

            <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 py-5">
                <div class="rounded-2xl shadow-lg p-4 md:p-6">

                    {{-- Tabs --}}
                    <div class="mb-5">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click.stop="setRoomType('all')"
                                :class="'{{ $selectedRoomType }}' === 'all'
                                    ? 'bg-teal-800 text-white border-teal-800/60 shadow-md'
                                    : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200 hover:text-gray-800'"
                                class="px-5 py-2 rounded-full text-sm font-semibold border transition-all duration-200">
                                Tất cả
                            </button>
                            @foreach ($roomTypes as $type)
                                <button type="button" wire:click.stop="setRoomType(@js($type['slug']))"
                                    wire:key="expanded-room-type-{{ $type['slug'] }}"
                                        :class="'{{ $selectedRoomType }}' === '{{ $type['slug'] }}'
                                        ? 'bg-teal-800 text-white border-teal-800/60 shadow-md'
                                        : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200 hover:text-gray-800'"
                                    class="px-5 py-2 rounded-full text-sm font-semibold border transition-all duration-200">
                                    {{ strtoupper($type['name']) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Search bar --}}
                    <div data-bar
                        x-data="{ locOpen: false, buoiOpen: false, guestsOpen: false }"
                        @click.outside="locOpen = false; buoiOpen = false; guestsOpen = false"
                        class="relative flex items-stretch rounded-2xl transition-all duration-300"
                        :class="open ? 'bg-gray-100 shadow-xl' : 'bg-white border border-gray-200 shadow-sm'"
                        style="overflow:visible;">

                        {{-- Highlight trượt theo field đang chọn (vị trí tính theo tỉ lệ flex cố định: loc=3, date=4, buoi=2, guests=2 / tổng 11) --}}
                        <div class="absolute rounded-xl bg-gray-200 pointer-events-none transition-all duration-300 ease-out" style="top:8px;bottom:8px;z-index:0;"
                            x-effect="
                                const active = locOpen ? 'loc' : open ? 'date' : buoiOpen ? 'buoi' : guestsOpen ? 'guests' : null;
                                if (!active) { $el.style.opacity = 0; }
                                else {
                                    const target = $el.parentElement.querySelector('[data-field=' + active + ']');
                                    if (target) {
                                        $el.style.opacity = 1;
                                        $el.style.left = target.offsetLeft + 'px';
                                        $el.style.width = target.offsetWidth + 'px';
                                    }
                                }
                            "></div>

                        {{-- Địa điểm --}}
                        <div data-field="loc" class="relative z-10 flex-[3] min-w-0">
                            <button type="button" @click="locOpen = !locOpen; buoiOpen = false; guestsOpen = false"
                                class="w-full h-14 px-4 flex flex-col justify-center items-start text-left rounded-l-2xl transition-colors">
                                <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Địa điểm</span>
                                <span class="text-sm font-semibold mt-0.5 truncate {{ $selectedLocation ? 'text-gray-900' : 'text-gray-400' }}">{{ $locationLabel }}</span>
                            </button>
                            <div x-show="locOpen" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-1"
                                class="absolute top-[calc(100%+6px)] left-0 w-64 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                                <button type="button" wire:click.stop="setLocation('')" @click="locOpen = false"
                                    wire:key="expanded-location-all"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ !$selectedLocation ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                    <span>Tất cả địa điểm</span>
                                    @if(!$selectedLocation) {!! $checkmarkSvg !!} @endif
                                </button>
                                @foreach($locations as $loc)
                                <button type="button" wire:click.stop="setLocation(@js($loc['slug']))" @click="locOpen = false"
                                    wire:key="expanded-location-{{ $loc['slug'] }}"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ $selectedLocation === $loc['slug'] ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                    <span>{{ $loc['name'] }}</span>
                                    @if($selectedLocation === $loc['slug']) {!! $checkmarkSvg !!} @endif
                                </button>
                                @endforeach
                            </div>
                        </div>


                        {{-- Thời gian --}}
                        <div @click.outside="open = false" data-field="date" class="relative z-10 flex-[4] min-w-0">
                            <button type="button" @click="open=!open"
                                class="w-full h-14 px-4 flex items-center gap-3 rounded-xl transition-colors">
                                <svg class="w-4 h-4 shrink-0 transition-colors duration-200"
                                    :class="open ? 'text-teal-600' : 'text-teal-500'"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <div class="flex flex-col min-w-0">
                                        <span :class="open ? 'text-teal-600' : 'text-gray-500'"
                                            class="text-[10px] font-bold uppercase tracking-widest leading-none transition-colors duration-200">Nhận phòng</span>
                                        <span class="text-sm font-semibold mt-0.5 truncate"
                                            :class="displayCheckIn ? 'text-gray-900' : 'text-gray-400'"
                                            x-text="displayCheckIn || '{{ $checkIn ?: 'Chọn ngày' }}'"></span>
                                    </div>
                                    <svg class="w-3 h-3 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <div class="flex flex-col min-w-0">
                                        <span :class="open ? 'text-teal-600' : 'text-gray-500'"
                                            class="text-[10px] font-bold uppercase tracking-widest leading-none transition-colors duration-200">Trả phòng</span>
                                        <span class="text-sm font-semibold mt-0.5 truncate"
                                            :class="displayCheckOut ? 'text-gray-900' : 'text-gray-400'"
                                            x-text="displayCheckOut || '{{ $checkOut ?: 'Chọn ngày' }}'"></span>
                                    </div>
                                </div>
                            </button>

                            {{-- Date picker dropdown --}}
                            <div x-show="open" x-cloak
                                x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                class="absolute top-[calc(100%+6px)] left-1/2 -translate-x-1/2 w-[640px] bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-y-auto"
                                style="max-height:75vh;">
                        <div class="py-5 px-6">
                            {{-- <div class="flex items-center justify-between mb-5">
                                <h3 class="text-base font-semibold text-gray-800">Chọn ngày &amp; giờ</h3>
                                <button @click="cancel()" class="p-1.5 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div> --}}
                            <div class="flex items-center justify-between mb-4">
                                <button @click="prevMonth()" class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                                <div class="flex-1 grid grid-cols-2 gap-4 text-center text-sm font-semibold text-gray-700">
                                    <span x-text="viewMonthName + ' ' + viewYear"></span>
                                    <span x-text="nextViewMonthName + ' ' + nextViewYear"></span>
                                </div>
                                <button @click="nextMonth()" class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <div class="grid grid-cols-7 mb-2">
                                        <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']"><div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div></template>
                                    </div>
                                    <div class="grid grid-cols-7 gap-y-1">
                                        <template x-for="(date, idx) in getCalendarDays(viewYear, viewMonth)" :key="idx">
                                            <div class="flex items-center justify-center">
                                                <template x-if="date !== null">
                                                    <button @click="selectDate(date)" @mouseenter="hoverDate = date" @mouseleave="hoverDate = null" :disabled="isPast(date)"
                                                        :class="{ 'bg-teal-800 text-white rounded-full': isSelected(date), 'bg-teal-50 rounded-none': isInRange(date) && !isSelected(date), 'rounded-l-full': isRangeStart(date) && checkOut, 'rounded-r-full': isRangeEnd(date), 'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date), 'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date) }"
                                                        class="w-9 h-9 text-sm font-medium flex items-center justify-center transition-colors" x-text="date.getDate()"></button>
                                                </template>
                                                <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <div class="grid grid-cols-7 mb-2">
                                        <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']"><div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div></template>
                                    </div>
                                    <div class="grid grid-cols-7 gap-y-1">
                                        <template x-for="(date, idx) in getCalendarDays(nextViewYear, nextViewMonth)" :key="idx">
                                            <div class="flex items-center justify-center">
                                                <template x-if="date !== null">
                                                    <button @click="selectDate(date)" @mouseenter="hoverDate = date" @mouseleave="hoverDate = null" :disabled="isPast(date)"
                                                        :class="{ 'bg-teal-800 text-white rounded-full': isSelected(date), 'bg-teal-50 rounded-none': isInRange(date) && !isSelected(date), 'rounded-l-full': isRangeStart(date) && checkOut, 'rounded-r-full': isRangeEnd(date), 'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date), 'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date) }"
                                                        class="w-9 h-9 text-sm font-medium flex items-center justify-center transition-colors" x-text="date.getDate()"></button>
                                                </template>
                                                <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-6 mt-5 pt-5 border-t border-gray-100">
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Giờ nhận phòng</p>
                                    <div class="flex items-start gap-2">
                                        <div class="flex-1 relative" x-ref="checkInHourBox2">
                                            <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Giờ</p>
                                            <div class="flex items-center gap-1.5 border border-gray-200 rounded-xl px-2.5 py-2">
                                                <button @click="checkInHour = Math.max(minCheckInHour, checkInHour - 1)"
                                                    :class="checkInHour <= minCheckInHour ? 'opacity-25 cursor-not-allowed' : 'hover:bg-gray-100'"
                                                    :disabled="checkInHour <= minCheckInHour"
                                                    class="w-6 h-6 rounded-full flex items-center justify-center text-gray-500 font-bold transition-colors select-none">−</button>
                                                <button type="button" @click="openHourDropdown('checkIn', $refs.checkInHourBox2)"
                                                    class="flex-1 text-center text-sm font-semibold text-gray-900 hover:text-teal-600 transition-colors" x-text="String(checkInHour).padStart(2,'0')"></button>
                                                <button @click="checkInHour = Math.min(23, checkInHour + 1)" class="w-6 h-6 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 font-bold transition-colors select-none">+</button>
                                            </div>
                                        </div>
                                        <template x-teleport="body">
                                            <div x-show="checkInHourOpen" x-cloak
                                                @click.outside="checkInHourOpen = false"
                                                x-transition:enter="transition ease-out duration-150"
                                                x-transition:enter-start="opacity-0 -translate-y-1"
                                                x-transition:enter-end="opacity-100 translate-y-0"
                                                x-transition:leave="transition ease-in duration-100"
                                                x-transition:leave-start="opacity-100 translate-y-0"
                                                x-transition:leave-end="opacity-0 -translate-y-1"
                                                :style="checkInHourPos.openUp ? { position: 'fixed', bottom: checkInHourPos.bottom + 'px', left: checkInHourPos.left + 'px', width: checkInHourPos.width + 'px', zIndex: 9999 } : { position: 'fixed', top: checkInHourPos.top + 'px', left: checkInHourPos.left + 'px', width: checkInHourPos.width + 'px', zIndex: 9999 }"
                                                style="max-height:12rem; overflow-y:auto;"
                                                class="bg-white rounded-xl border border-gray-200 shadow-xl p-1.5 grid grid-cols-4 gap-1">
                                                <template x-for="h in availableCheckInHours" :key="h">
                                                    <button type="button" @click.stop="checkInHour = h; checkInHourOpen = false"
                                                        :class="checkInHour === h ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                        class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                        x-text="String(h).padStart(2,'0')"></button>
                                                </template>
                                            </div>
                                        </template>
                                        <div class="text-gray-300 text-lg font-light pt-6">:</div>
                                        <div class="flex-1">
                                            <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Phút</p>
                                            <div class="grid grid-cols-4 gap-1">
                                                <template x-for="m in availableCheckInMinutes" :key="m">
                                                    <button @click="checkInMin = m"
                                                        :class="checkInMin === m ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                        class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                        x-text="String(m).padStart(2,'0')"></button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">
                                        Giờ trả phòng
                                        <span x-show="isSameDayBooking" class="text-teal-600 normal-case font-normal ml-1">(cùng ngày)</span>
                                    </p>
                                    <div class="flex items-start gap-2">
                                        <div class="flex-1 relative" x-ref="checkOutHourBox2">
                                            <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Giờ</p>
                                            <div class="flex items-center gap-1.5 border border-gray-200 rounded-xl px-2.5 py-2">
                                                <button @click="if(checkOutHour > 0){ checkOutHour--; ensureCheckOutAfterCheckIn(); }"
                                                    :class="(isSameDayBooking && checkOutHour <= checkInHour) ? 'opacity-25 cursor-not-allowed' : 'hover:bg-gray-100'"
                                                    :disabled="isSameDayBooking && checkOutHour <= checkInHour"
                                                    class="w-6 h-6 rounded-full flex items-center justify-center text-gray-500 font-bold transition-colors select-none">−</button>
                                                <button type="button" @click="openHourDropdown('checkOut', $refs.checkOutHourBox2)"
                                                    class="flex-1 text-center text-sm font-semibold text-gray-900 hover:text-teal-600 transition-colors" x-text="String(checkOutHour).padStart(2,'0')"></button>
                                                <button @click="if(checkOutHour < 23){ checkOutHour++; ensureCheckOutAfterCheckIn(); }" class="w-6 h-6 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500 font-bold transition-colors select-none">+</button>
                                            </div>
                                        </div>
                                        <template x-teleport="body">
                                            <div x-show="checkOutHourOpen" x-cloak
                                                @click.outside="checkOutHourOpen = false"
                                                x-transition:enter="transition ease-out duration-150"
                                                x-transition:enter-start="opacity-0 -translate-y-1"
                                                x-transition:enter-end="opacity-100 translate-y-0"
                                                x-transition:leave="transition ease-in duration-100"
                                                x-transition:leave-start="opacity-100 translate-y-0"
                                                x-transition:leave-end="opacity-0 -translate-y-1"
                                                :style="checkOutHourPos.openUp ? { position: 'fixed', bottom: checkOutHourPos.bottom + 'px', left: checkOutHourPos.left + 'px', width: checkOutHourPos.width + 'px', zIndex: 9999 } : { position: 'fixed', top: checkOutHourPos.top + 'px', left: checkOutHourPos.left + 'px', width: checkOutHourPos.width + 'px', zIndex: 9999 }"
                                                style="max-height:12rem; overflow-y:auto;"
                                                class="bg-white rounded-xl border border-gray-200 shadow-xl p-1.5 grid grid-cols-4 gap-1">
                                                <template x-for="h in availableCheckoutHours" :key="h">
                                                    <button type="button" @click.stop="checkOutHour = h; ensureCheckOutAfterCheckIn(); checkOutHourOpen = false"
                                                        :class="checkOutHour === h ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                        class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                        x-text="String(h).padStart(2,'0')"></button>
                                                </template>
                                            </div>
                                        </template>
                                        <div class="text-gray-300 text-lg font-light pt-6">:</div>
                                        <div class="flex-1">
                                            <p class="text-[10px] text-gray-400 uppercase tracking-wide mb-1.5">Phút</p>
                                            <div class="grid grid-cols-4 gap-1">
                                                <template x-for="m in (checkIn && checkOut && isSameDay(checkIn, checkOut) && checkOutHour === checkInHour ? minutes.filter(m => m > checkInMin) : minutes)" :key="m">
                                                    <button @click="checkOutMin = m; ensureCheckOutAfterCheckIn()"
                                                        :class="checkOutMin === m ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200 hover:border-teal-400 hover:text-teal-600'"
                                                        class="py-2 rounded-lg border text-xs font-semibold transition-colors text-center"
                                                        x-text="String(m).padStart(2,'0')"></button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {{-- <div class="flex items-center justify-between mt-5 pt-4 border-t border-gray-100">
                                <button @click="checkIn = null; checkOut = null" class="text-sm text-gray-500 hover:text-gray-700 underline underline-offset-2 transition-colors">Xóa ngày</button>
                                <div class="flex gap-3">
                                    <button @click="cancel()" class="px-5 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-600 hover:bg-gray-50 transition-colors font-medium">Hủy</button>
                                    <button @click="confirm()" class="px-5 py-2.5 bg-teal-700 hover:bg-teal-800 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm">Xác nhận</button>
                                </div>
                            </div> --}}
                        </div>
                            </div>
                        </div>

                        {{-- Loại đặt --}}
                        <div data-field="buoi" class="relative z-10 flex-[2] min-w-0">
                            <button type="button" @click="buoiOpen = !buoiOpen; locOpen = false; guestsOpen = false"
                                class="w-full h-14 px-4 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                                <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Loại đặt</span>
                                <span class="text-sm font-semibold mt-0.5 {{ $selectedBuoi ? 'text-gray-900' : 'text-gray-400' }}">{{ $buoiLabel }}</span>
                            </button>
                            <div x-show="buoiOpen" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-1"
                                class="absolute top-[calc(100%+6px)] left-0 w-48 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                                @php $buoiOpts = ['' => 'Tất cả', '1' => 'Theo giờ', '2' => 'Theo ngày']; @endphp
                                @foreach($buoiOpts as $bVal => $bLbl)
                                <button type="button" wire:click.stop="setBuoi(@js($bVal))" @click="buoiOpen = false"
                                    wire:key="expanded-buoi-{{ $bVal ?: 'all' }}"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$bVal && !$selectedBuoi) || ($bVal && $selectedBuoi === $bVal) ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                    <span>{{ $bLbl }}</span>
                                    @if((!$bVal && !$selectedBuoi) || ($bVal && $selectedBuoi === $bVal)) {!! $checkmarkSvg !!} @endif
                                </button>
                                @endforeach
                            </div>
                        </div>


                        {{-- Số người --}}
                        <div data-field="guests" class="relative z-10 flex-[2] min-w-0">
                            <button type="button" @click="guestsOpen = !guestsOpen; locOpen = false; buoiOpen = false"
                                class="w-full h-14 px-4 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                                <span class="text-[10px] font-bold uppercase tracking-widest leading-none text-gray-500">Số người</span>
                                <span class="text-sm font-semibold mt-0.5 {{ $selectedGuests ? 'text-gray-900' : 'text-gray-400' }}">{{ $guestsLabel }}</span>
                            </button>
                            <div x-show="guestsOpen" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-1"
                                class="absolute top-[calc(100%+6px)] right-0 w-52 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                                @php $guestOpts = ['' => 'Tất cả', '1' => '1 người', '2' => '2 người', '3' => '3 người', '4' => '4 người', '5' => '5+ người']; @endphp
                                @foreach($guestOpts as $gVal => $gLbl)
                                <button type="button" wire:click.stop="setGuests(@js($gVal))" @click="guestsOpen = false"
                                    wire:key="expanded-guests-{{ $gVal ?: 'all' }}"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal) ? 'font-semibold text-teal-700' : 'text-gray-700' }}">
                                    <span>{{ $gLbl }}</span>
                                    @if((!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal)) {!! $checkmarkSvg !!} @endif
                                </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Search --}}
                        <div class="flex items-center px-3 shrink-0">
                            <button type="button"
                                x-on:click.stop.prevent="formOpen = false; submitSearch()"
                                class="w-11 h-11 bg-teal-600 hover:bg-teal-700 text-white rounded-full
                                transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    {{-- Backdrop --}}
    <div x-show="menuOpen"
         x-cloak
         style="display:none; position:fixed; inset:0; z-index:1999; background:rgba(0,0,0,0.45);"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="menuOpen = false"></div>

    {{-- Slide-in menu drawer --}}
    <div x-show="menuOpen"
         x-cloak
         class="menu-drawer"
         style="position:fixed; top:0; right:0; bottom:0; width:min(340px,90vw); z-index:2000; background:white; box-shadow:-8px 0 40px rgba(0,0,0,0.18); overflow-y:auto;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-250"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full">

        {{-- Topbar: logo + close --}}
        {{-- <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #f3f4f6; flex-shrink:0;">
            <span style="font-size:16px; font-weight:700; color:#111827; letter-spacing:-.3px;">Menu</span>
            <button @click="menuOpen = false"
                    style="width:34px; height:34px; border-radius:50%; background:#f3f4f6; border:none; color:#6b7280; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s;"
                    onmouseover="this.style.background='#e5e7eb';" onmouseout="this.style.background='#f3f4f6';">
                <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div> --}}

        {{-- Nav items --}}
        <div style="padding:8px 0; flex:1;">
            @foreach($navLinks as $link)
            <a href="{{ $link['url'] }}"
               wire:key="nav-link-{{ $link['url'] }}"
               @click="menuOpen = false"
               style="display:flex; align-items:center; gap:14px; padding:13px 20px; text-decoration:none; color:#374151; transition:all .15s; cursor:pointer;"
               onmouseover="this.style.background='#f9fafb'; this.style.color='#0f766e';"
               onmouseout="this.style.background=''; this.style.color='#374151';">
                {{-- <span style="width:34px; height:34px; border-radius:9px; background:#f3f4f6; display:flex; align-items:center; justify-content:center; flex-shrink:0;">{!! $link['icon'] !!}</span> --}}
                <span style="font-size:14px; font-weight:600; flex:1;">{{ $link['label'] }}</span>
                <svg style="width:13px;height:13px;color:#d1d5db; flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            @endforeach
        </div>

        {{-- Divider + Auth --}}
        <div style="border-top:1px solid #f3f4f6; padding:12px 20px 20px; flex-shrink:0;"
             x-data="{
                authUser: null,
                init() {
                    this.load();
                    window.addEventListener('auth-state-changed', () => this.load());
                },
                load() {
                    try {
                        const t = localStorage.getItem('auth_token');
                        const u = localStorage.getItem('auth_user');
                        this.authUser = (t && u) ? JSON.parse(u) : null;
                    } catch(e) { this.authUser = null; }
                },
                openLogin() { window.dispatchEvent(new CustomEvent('open-auth-modal')); menuOpen = false; }
             }">
            <template x-if="!authUser">
                <button @click="openLogin()"
                        style="width:100%; display:flex; align-items:center; gap:12px; padding:13px 16px; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:12px; cursor:pointer; transition:all .15s; text-align:left;"
                        onmouseover="this.style.background='#f0fdfa'; this.style.borderColor='#99f6e4';"
                        onmouseout="this.style.background='#f9fafb'; this.style.borderColor='#e5e7eb';">
                    <span style="width:34px; height:34px; border-radius:9px; background:#e5e7eb; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg style="width:16px;height:16px;color:#6b7280;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </span>
                    <span style="font-size:14px; font-weight:600; color:#374151; flex:1;">Đăng nhập</span>
                    <svg style="width:13px;height:13px;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </template>
            <template x-if="authUser">
                <div style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:12px;">
                    <span style="width:34px; height:34px; border-radius:50%; background:#0f766e; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:white; font-size:14px; font-weight:700;"
                          x-text="authUser.fullname ? authUser.fullname.charAt(0).toUpperCase() : '?'"></span>
                    <span style="font-size:14px; font-weight:600; color:#111827; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" x-text="authUser.fullname"></span>
                </div>
            </template>
        </div>
    </div>
</div>
