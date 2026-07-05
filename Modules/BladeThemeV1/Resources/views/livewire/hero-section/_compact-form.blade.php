        {{-- Form mở rộng — mobile: popup toàn màn hình; desktop: panel sổ xuống dưới pill
             (breakpoint 1024px, đồng bộ với lg: dùng trong header/banner-form) --}}
        <style>
            .hero-compact-form-panel { background: #fff; }
            @media (max-width: 1023px) {
                .hero-compact-form-panel {
                    position: fixed;
                    inset: 0;
                    z-index: 2000;
                    overflow-y: auto;
                    -webkit-overflow-scrolling: touch;
                }
            }
            @media (min-width: 1024px) {
                .hero-compact-form-panel {
                    border-bottom: 1px solid #e5e7eb;
                    box-shadow: 0 4px 24px rgba(0,0,0,.12);
                }
            }
        </style>
        <div x-show="formOpen"
             x-data="heroDatePicker({{ $selectedBuoi === '2' ? 'true' : 'false' }})"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-1"
             class="hero-compact-form-panel">

            {{-- Mobile: thanh tiêu đề + nút đóng popup --}}
            <div class="lg:hidden flex items-center justify-between px-4 pt-4 pb-1">
                <span class="text-sm font-bold text-gray-900">Tìm kiếm</span>
                <button type="button" @click="formOpen = false"
                    class="w-9 h-9 rounded-full flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="w-full max-w-11xl mx-auto px-4 sm:px-6 py-5">
                <div class="rounded-2xl shadow-lg p-4 md:p-6">

                    {{-- Tabs --}}
                    <div class="mb-5">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click.stop="setRoomType('all')"
                                :class="'{{ $selectedRoomType }}' === 'all'
                                    ? 'bg-[var(--color-primary)] text-white border-[rgba(var(--color-primary-rgb),0.6)] shadow-md'
                                    : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200 hover:text-gray-800'"
                                class="px-5 py-2 rounded-full text-sm font-semibold border transition-all duration-200">
                                Tất cả
                            </button>
                            @foreach ($roomTypes as $type)
                                <button type="button" wire:click.stop="setRoomType(@js($type['slug']))"
                                    wire:key="expanded-room-type-{{ $type['slug'] }}"
                                        :class="'{{ $selectedRoomType }}' === '{{ $type['slug'] }}'
                                        ? 'bg-[var(--color-primary)] text-white border-[rgba(var(--color-primary-rgb),0.6)] shadow-md'
                                        : 'bg-gray-100 text-gray-600 border-gray-200 hover:bg-gray-200 hover:text-gray-800'"
                                    class="px-5 py-2 rounded-full text-sm font-semibold border transition-all duration-200">
                                    {{ strtoupper($type['name']) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Search bar --}}
                    <div data-bar
                        x-data="{
                            locOpen: false, guestsOpen: false, mobileStep: 1, locating: false,
                            locationSearch: '', selectedLocationSlug: '{{ $selectedLocation }}', mobileLocations: @js($locations),
                            locateMe() {
                                this.locating = true;
                                window.heroLocateNearest(
                                    (slug) => $wire.setLocation(slug),
                                    @js($locations),
                                    (loc) => { this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug; this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep); },
                                    (msg) => { this.locating = false; alert(msg); }
                                );
                            }
                        }"
                        @click.outside="locOpen = false; guestsOpen = false">

                    {{-- Desktop: thanh ngang đầy đủ (đồng bộ breakpoint lg với header) --}}
                    <div class="relative hidden lg:flex items-stretch rounded-2xl border transition-all duration-300"
                        :class="(open || locOpen || guestsOpen) ? 'bg-gray-100 border-gray-100 shadow-xl' : 'bg-white border-gray-200 shadow-sm'"
                        style="overflow:visible;">

                        {{-- Highlight trượt theo field đang chọn (vị trí tính theo tỉ lệ flex cố định: loc=3, date=4, guests=2 / tổng 9).
                             Field đang mở → nền trắng nổi khối trên nền xám của thanh, giống Airbnb. --}}
                        <div class="absolute rounded-xl pointer-events-none transition-all duration-300 ease-out" style="top:8px;bottom:8px;z-index:0; background-color:#ffffff; box-shadow:0 1px 4px rgba(0,0,0,0.15);"
                            x-effect="
                                const active = locOpen ? 'loc' : open ? 'date' : guestsOpen ? 'guests' : null;
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
                            <button type="button" @click="locOpen = !locOpen; guestsOpen = false"
                                class="w-full h-16 px-4 flex flex-col justify-center items-start text-left rounded-l-2xl transition-colors">
                                <span class="text-sm font-bold leading-none text-gray-900">Địa điểm</span>
                                <span class="text-sm font-medium mt-1 truncate {{ $selectedLocation ? 'text-gray-900' : 'text-gray-400' }}">{{ $locationLabel }}</span>
                            </button>
                            <div x-show="locOpen" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 translate-y-0"
                                x-transition:leave-end="opacity-0 translate-y-1"
                                class="absolute top-[calc(100%+6px)] left-0 w-64 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
                                <button type="button" @click="locateMe()" :disabled="locating"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center gap-2 text-teal-700 font-semibold transition-colors border-b border-gray-100 mb-1 disabled:opacity-60">
                                    <svg x-show="!locating" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <svg x-show="locating" x-cloak class="w-4 h-4 shrink-0 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V2.5"/>
                                    </svg>
                                    <span x-text="locating ? 'Đang định vị...' : 'Vị trí của tôi'"></span>
                                </button>
                                <button type="button" wire:click.stop="setLocation('')" @click="locOpen = false"
                                    wire:key="expanded-location-all"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ !$selectedLocation ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700' }}">
                                    <span>Tất cả địa điểm</span>
                                    @if(!$selectedLocation) {!! $checkmarkSvg !!} @endif
                                </button>
                                @foreach($locations as $loc)
                                <button type="button" wire:click.stop="setLocation(@js($loc['slug']))" @click="locOpen = false"
                                    wire:key="expanded-location-{{ $loc['slug'] }}"
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ $selectedLocation === $loc['slug'] ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700' }}">
                                    <span>{{ $loc['name'] }}</span>
                                    @if($selectedLocation === $loc['slug']) {!! $checkmarkSvg !!} @endif
                                </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="relative z-10 self-center w-px h-8 bg-gray-200 shrink-0"></div>

                        {{-- Thời gian --}}
                        <div @click.outside="open = false" data-field="date" class="relative z-10 flex-[4] min-w-0">
                            <button type="button" @click="open=!open; if (open) openDateDropdown($refs.dateDropdownCompact)"
                                class="w-full h-16 px-4 flex items-center gap-3 rounded-xl transition-colors">
                                <svg class="w-4 h-4 shrink-0 transition-colors duration-200"
                                    :class="open ? 'text-[var(--color-primary)]' : 'text-[rgba(var(--color-primary-rgb),0.7)]'"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <div class="flex flex-col min-w-0">
                                        <span :class="open ? 'text-[var(--color-primary)]' : 'text-gray-900'"
                                            class="text-sm font-bold leading-none transition-colors duration-200">Nhận phòng</span>
                                        <span class="text-sm font-medium mt-1 truncate"
                                            :class="displayCheckIn ? 'text-gray-900' : 'text-gray-400'"
                                            x-text="displayCheckIn || '{{ $checkIn ?: 'Chọn ngày' }}'"></span>
                                    </div>
                                    <svg class="w-3 h-3 text-gray-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <div class="flex flex-col min-w-0">
                                        <span :class="open ? 'text-[var(--color-primary)]' : 'text-gray-900'"
                                            class="text-sm font-bold leading-none transition-colors duration-200">Trả phòng</span>
                                        <span class="text-sm font-medium mt-1 truncate"
                                            :class="displayCheckOut ? 'text-gray-900' : 'text-gray-400'"
                                            x-text="displayCheckOut || '{{ $checkOut ?: 'Chọn ngày' }}'"></span>
                                    </div>
                                </div>
                            </button>

                            {{-- Date picker dropdown --}}
                            <div x-show="open" x-cloak x-ref="dateDropdownCompact"
                                x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                class="absolute top-[calc(100%+6px)] left-1/2 {{ $selectedBuoi === '2' ? 'w-[640px]' : 'w-[380px]' }} bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-y-auto"
                                style="max-height:75vh; transform:translateX(-50%);">
                        <div class="py-5 px-6">

                            {{-- Tabs Theo giờ / Theo ngày --}}
                            <div class="flex items-center gap-1 mb-5 p-1 bg-gray-100 rounded-full w-fit mx-auto">
                                <button type="button" wire:click.stop="setBuoi('1')" @click="dayMode = false; if (checkIn) { checkOut = checkIn }"
                                    class="px-5 py-2 rounded-full text-sm font-semibold transition-colors {{ $selectedBuoi !== '2' ? 'bg-white text-[var(--color-primary)] shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                                    Theo giờ
                                </button>
                                <button type="button" wire:click.stop="setBuoi('2')" @click="dayMode = true"
                                    class="px-5 py-2 rounded-full text-sm font-semibold transition-colors {{ $selectedBuoi === '2' ? 'bg-white text-[var(--color-primary)] shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                                    Theo ngày
                                </button>
                            </div>

                            @if($selectedBuoi === '2')
                            {{-- THEO NGÀY: lịch 2 tháng, chọn khoảng ngày, giờ nhận/trả cố định 14:00 / 12:00 --}}
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
                                                        :class="{ 'bg-[var(--color-primary)] text-white rounded-full': isSelected(date), 'bg-[rgba(var(--color-primary-rgb),0.1)] rounded-none': isInRange(date) && !isSelected(date), 'rounded-l-full': isRangeStart(date) && checkOut, 'rounded-r-full': isRangeEnd(date), 'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date), 'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date) }"
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
                                                        :class="{ 'bg-[var(--color-primary)] text-white rounded-full': isSelected(date), 'bg-[rgba(var(--color-primary-rgb),0.1)] rounded-none': isInRange(date) && !isSelected(date), 'rounded-l-full': isRangeStart(date) && checkOut, 'rounded-r-full': isRangeEnd(date), 'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date), 'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date) }"
                                                        class="w-9 h-9 text-sm font-medium flex items-center justify-center transition-colors" x-text="date.getDate()"></button>
                                                </template>
                                                <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            @else
                            {{-- THEO GIỜ: lịch 1 tháng, chọn đúng 1 ngày + badge giờ nhận/trả --}}
                            <div class="flex items-center justify-between mb-4">
                                <button @click="prevMonth()" class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                                <span class="text-sm font-semibold text-gray-700" x-text="viewMonthName + ' ' + viewYear"></span>
                                <button @click="nextMonth()" class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                            <div class="max-w-[280px] mx-auto">
                                <div class="grid grid-cols-7 mb-2">
                                    <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']"><div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div></template>
                                </div>
                                <div class="grid grid-cols-7 gap-y-1">
                                    <template x-for="(date, idx) in getCalendarDays(viewYear, viewMonth)" :key="idx">
                                        <div class="flex items-center justify-center">
                                            <template x-if="date !== null">
                                                <button @click="selectSingleDate(date)" :disabled="isPast(date)"
                                                    :class="{ 'bg-[var(--color-primary)] text-white': isSelected(date), 'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date), 'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date) }"
                                                    class="w-9 h-9 rounded-full text-sm font-medium flex items-center justify-center transition-colors" x-text="date.getDate()"></button>
                                            </template>
                                            <template x-if="date === null"><div class="w-9 h-9"></div></template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Badge giờ nhận / trả phòng --}}
                            <div class="mt-5 pt-5 border-t border-gray-100 space-y-4">
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Giờ nhận phòng</p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="h in hourBadges" :key="'in-' + h">
                                            <button type="button" x-show="availableStartHourBadges.includes(h)"
                                                @click="checkInHour = h"
                                                :class="checkInHour === h ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'bg-white text-gray-600 border-gray-200 hover:border-[rgba(var(--color-primary-rgb),0.5)] hover:text-[var(--color-primary)]'"
                                                class="w-14 h-9 rounded-full border text-xs font-semibold transition-colors"
                                                x-text="String(h).padStart(2,'0') + ':00'"></button>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Giờ trả phòng</p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="h in hourBadges" :key="'out-' + h">
                                            <button type="button" x-show="availableEndHourBadges.includes(h)"
                                                @click="checkOutHour = h"
                                                :class="checkOutHour === h ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]' : 'bg-white text-gray-600 border-gray-200 hover:border-[rgba(var(--color-primary-rgb),0.5)] hover:text-[var(--color-primary)]'"
                                                class="w-14 h-9 rounded-full border text-xs font-semibold transition-colors"
                                                x-text="String(h).padStart(2,'0') + ':00'"></button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                            </div>
                        </div>

                        <div class="relative z-10 self-center w-px h-8 bg-gray-200 shrink-0"></div>

                        {{-- Số người --}}
                        <div data-field="guests" class="relative z-10 flex-[2] min-w-0">
                            <button type="button" @click="guestsOpen = !guestsOpen; locOpen = false"
                                class="w-full h-16 px-4 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                                <span class="text-sm font-bold leading-none text-gray-900">Khách</span>
                                <span class="text-sm font-medium mt-1 {{ $selectedGuests ? 'text-gray-900' : 'text-gray-400' }}">{{ $guestsLabel }}</span>
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
                                    class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal) ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700' }}">
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
                                class="w-12 h-12 bg-[var(--color-primary)] hover:opacity-90 text-white rounded-full
                                transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    @include('bladethemev1::livewire.hero-section._mobile-steps', ['boxSuffix' => 'Compact', 'searchAction' => 'formOpen = false; submitSearch()'])

                    </div>

                </div>
            </div>
        </div>
