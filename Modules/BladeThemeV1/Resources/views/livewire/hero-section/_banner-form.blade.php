            <div x-data="heroDatePicker({{ $selectedBuoi === '2' ? 'true' : 'false' }})" class="{{ $formClass }}">

                {{-- Search bar — Airbnb style --}}
                <div data-bar
                    x-data="{
                        locOpen: false, guestsOpen: false, mobileStep: 1, locating: false, hoverField: null,
                        locationSearch: '', selectedLocationSlug: '{{ $selectedLocation }}', mobileLocations: @js($locations),
                        selectedGuestsVal: '{{ $selectedGuests }}', selectedRoomType: '{{ $selectedRoomType }}',
                        guestOptions: { '': 'Tất cả', '1': '1 người', '2': '2 người', '3': '3 người', '4': '4 người', '5': '5+ người' },
                        get locationLabel() {
                            if (!this.selectedLocationSlug) return 'Chọn địa điểm';
                            const loc = this.mobileLocations.find(l => l.slug === this.selectedLocationSlug);
                            return loc ? loc.name : 'Chọn địa điểm';
                        },
                        get guestsLabel() { return this.guestOptions[this.selectedGuestsVal] || 'Thêm khách'; },
                        pickLocation(slug) {
                            this.selectedLocationSlug = slug;
                            this.locOpen = false;
                            this.open = true;
                            this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop));
                        },
                        locateMe() {
                            this.locating = true;
                            window.heroLocateNearest(
                                (slug) => {},
                                @js($locations),
                                (loc) => { this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug; this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep); this.open = true; this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop)); },
                                (msg) => { this.locating = false; alert(msg); }
                            );
                        },
                        // Pill gọn trong header (đã bị teleport ra ngoài scope này) bắn event này để
                        // yêu cầu mở đúng popup của field tương ứng, thay vì phải bấm 2 lần. Đợi 180ms
                        // (khớp với thời gian giãn hàng-2 ở header-main, 150ms) trước khi mở, vì 2 lý do:
                        // 1) click vào pill cũng là 1 click ngoài phạm vi data-bar (@click.outside bên
                        //    dưới) — set cờ ngay trong cùng lượt xử lý click đó sẽ bị @click.outside
                        //    tắt lại ngay sau; 2) vị trí popup (đặc biệt field date, cần đo bằng JS)
                        //    chỉ chính xác sau khi hàng-2 đã giãn xong, nếu đo lúc đang giãn dở sẽ ra
                        //    toạ độ sai và không tự sửa lại được.
                        handleHeroOpenField(field) {
                            setTimeout(() => {
                                this.locOpen = false; this.guestsOpen = false; this.open = false;
                                if (field === 'loc') { this.locOpen = true; }
                                else if (field === 'guests') { this.guestsOpen = true; }
                                else if (field === 'date') {
                                    this.open = true;
                                    this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop));
                                }
                            }, 180);
                        }
                    }"
                    x-on:hero-open-field.window="handleHeroOpenField($event.detail)"
                    @click.outside="open = false; locOpen = false; guestsOpen = false">

                {{-- Desktop: thanh ngang đầy đủ (đồng bộ breakpoint lg với header) --}}
                <div class="relative hidden lg:flex items-stretch max-w-4xl mx-auto rounded-full border transition-all duration-300"
                    :class="(open || locOpen || guestsOpen) ? 'bg-gray-100 border-gray-100 shadow-2xl' : 'bg-white border-gray-200 shadow-lg'"
                    style="overflow:visible;">

                    {{-- Highlight trượt theo field đang chọn (đo pixel thật của field, không dùng % cố định).
                         Field đang thực sự mở (không chỉ hover) → nền trắng nổi khối trên nền xám của
                         thanh; chỉ hover (chưa click) → nền xám nhạt, giống Airbnb. --}}
                    <div class="absolute rounded-full pointer-events-none transition-all duration-300 ease-out" style="top:0;bottom:0;z-index:0;"
                        x-effect="
                            const activeOpen = locOpen ? 'loc' : open ? 'date' : guestsOpen ? 'guests' : null;
                            const active = activeOpen || hoverField;
                            if (!active) { $el.style.opacity = 0; }
                            else {
                                const target = $el.parentElement.querySelector('[data-field=' + active + ']');
                                if (target) {
                                    $el.style.opacity = 1;
                                    $el.style.left = target.offsetLeft + 'px';
                                    $el.style.width = target.offsetWidth + 'px';
                                    $el.style.backgroundColor = activeOpen ? '#ffffff' : '#e5e7eb';
                                    $el.style.boxShadow = activeOpen ? '0 1px 4px rgba(0,0,0,0.15)' : 'none';
                                }
                            }
                        "></div>

                    {{-- Địa điểm --}}
                    <div data-field="loc" class="relative z-10 flex-[3] min-w-0"
                        @mouseenter="hoverField = 'loc'" @mouseleave="hoverField = null">
                        <button type="button" @click="locOpen = !locOpen; open = false; guestsOpen = false"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-l-full transition-colors">
                            <span class="text-sm font-bold leading-none text-gray-900">Địa điểm</span>
                            <span class="text-sm font-medium mt-1 truncate" :class="selectedLocationSlug ? 'text-gray-900' : 'text-gray-400'" x-text="locationLabel"></span>
                        </button>
                        <div x-show="locOpen" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] left-0 w-72 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 py-2">
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
                            <button type="button" @click="pickLocation('')"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors"
                                :class="!selectedLocationSlug ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700'">
                                <span>Tất cả địa điểm</span>
                                <span x-show="!selectedLocationSlug">{!! $checkmarkSvg !!}</span>
                            </button>
                            @foreach($locations as $loc)
                            <button type="button" @click="pickLocation(@js($loc['slug']))"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors"
                                :class="selectedLocationSlug === @js($loc['slug']) ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700'">
                                <span>{{ $loc['name'] }}</span>
                                <span x-show="selectedLocationSlug === @js($loc['slug'])">{!! $checkmarkSvg !!}</span>
                            </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="relative z-10 self-center w-px h-8 bg-gray-200 shrink-0"></div>

                    {{-- Thời gian --}}
                    <div @click.outside="open = false" data-field="date" class="relative z-10 flex-[3] min-w-0"
                        @mouseenter="hoverField = 'date'" @mouseleave="hoverField = null">
                        <button type="button" @click="open=!open; locOpen = false; guestsOpen = false; if (open) openDateDropdown($refs.dateDropdownDesktop)"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                            <span :class="open ? 'text-[var(--color-primary)]' : 'text-gray-900'"
                                class="text-sm font-bold leading-none transition-colors duration-200">Thời gian</span>
                            <span class="text-sm font-medium mt-1 truncate"
                                :class="(displayCheckIn && displayCheckOut) ? 'text-gray-900' : 'text-gray-400'"
                                x-text="(displayCheckIn && displayCheckOut) ? (displayCheckIn + ' → ' + displayCheckOut) : '{{ ($checkIn && $checkOut) ? $checkIn . ' → ' . $checkOut : 'Thêm ngày' }}'"></span>
                        </button>

                        {{-- Date picker dropdown --}}
                        <div x-show="open" x-cloak x-ref="dateDropdownDesktop"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute top-[calc(100%+8px)] left-1/2 bg-white rounded-2xl shadow-2xl border border-gray-100 z-50 overflow-y-auto"
                            :class="dayMode ? 'w-[640px]' : 'w-[380px]'"
                            style="max-height:75vh; transform:translateX(-50%);">
                    <div class="py-5 px-6">

                        {{-- Tabs Theo giờ / Theo ngày --}}
                        <div class="flex items-center gap-1 mb-5 p-1 bg-gray-100 rounded-full w-fit mx-auto">
                            <button type="button" @click="dayMode = false; if (checkIn) { checkOut = checkIn }"
                                class="px-5 py-2 rounded-full text-sm font-semibold transition-colors"
                                :class="!dayMode ? 'bg-white text-[var(--color-primary)] shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                                Theo giờ
                            </button>
                            <button type="button" @click="dayMode = true"
                                class="px-5 py-2 rounded-full text-sm font-semibold transition-colors"
                                :class="dayMode ? 'bg-white text-[var(--color-primary)] shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                                Theo ngày
                            </button>
                        </div>

                        <template x-if="dayMode">
                        <div>
                        {{-- THEO NGÀY: lịch 2 tháng, chọn khoảng ngày, giờ nhận/trả cố định 14:00 / 12:00 --}}
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
                                                        'bg-[var(--color-primary)] text-white rounded-full': isSelected(date),
                                                        'bg-[rgba(var(--color-primary-rgb),0.1)] rounded-none': isInRange(date) && !isSelected(date),
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
                                                        'bg-[var(--color-primary)] text-white rounded-full': isSelected(date),
                                                        'bg-[rgba(var(--color-primary-rgb),0.1)] rounded-none': isInRange(date) && !isSelected(date),
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
                        </div>
                        </template>
                        <template x-if="!dayMode">
                        <div>
                        {{-- THEO GIỜ: lịch 1 tháng, chọn đúng 1 ngày + badge giờ nhận/trả --}}
                        <div class="flex items-center justify-between mb-4">
                            <button @click="prevMonth()"
                                class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <span class="text-sm font-semibold text-gray-700" x-text="viewMonthName + ' ' + viewYear"></span>
                            <button @click="nextMonth()"
                                class="p-2 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        <div class="max-w-[280px] mx-auto">
                            <div class="grid grid-cols-7 mb-2">
                                <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']">
                                    <div class="text-center text-xs font-medium text-gray-400 py-1" x-text="d"></div>
                                </template>
                            </div>
                            <div class="grid grid-cols-7 gap-y-1">
                                <template x-for="(date, idx) in getCalendarDays(viewYear, viewMonth)" :key="idx">
                                    <div class="flex items-center justify-center">
                                        <template x-if="date !== null">
                                            <button @click="selectSingleDate(date)"
                                                :disabled="isPast(date)"
                                                :class="{
                                                    'bg-[var(--color-primary)] text-white': isSelected(date),
                                                    'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                                    'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date),
                                                }"
                                                class="w-9 h-9 rounded-full text-sm font-medium flex items-center justify-center transition-colors"
                                                x-text="date.getDate()">
                                            </button>
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
                                            :class="checkInHour === h
                                                ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                                : 'bg-white text-gray-600 border-gray-200 hover:border-[rgba(var(--color-primary-rgb),0.5)] hover:text-[var(--color-primary)]'"
                                            class="w-14 h-9 rounded-full border text-xs font-semibold transition-colors"
                                            x-text="String(h).padStart(2,'0') + ':00'">
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Giờ trả phòng</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="h in hourBadges" :key="'out-' + h">
                                        <button type="button" x-show="availableEndHourBadges.includes(h)"
                                            @click="checkOutHour = h"
                                            :class="checkOutHour === h
                                                ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                                : 'bg-white text-gray-600 border-gray-200 hover:border-[rgba(var(--color-primary-rgb),0.5)] hover:text-[var(--color-primary)]'"
                                            class="w-14 h-9 rounded-full border text-xs font-semibold transition-colors"
                                            x-text="String(h).padStart(2,'0') + ':00'">
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        </div>
                        </template>
                    </div>
                        </div>
                    </div>

                    <div class="relative z-10 self-center w-px h-8 bg-gray-200 shrink-0"></div>

                    {{-- Số người --}}
                    <div data-field="guests" class="relative z-10 flex-[2] min-w-0"
                        @mouseenter="hoverField = 'guests'" @mouseleave="hoverField = null">
                        <button type="button" @click="guestsOpen = !guestsOpen; open = false; locOpen = false"
                            class="w-full h-16 px-5 flex flex-col justify-center items-start text-left rounded-xl transition-colors">
                            <span class="text-sm font-bold leading-none text-gray-900">Khách</span>
                            <span class="text-sm font-medium mt-1" :class="selectedGuestsVal ? 'text-gray-900' : 'text-gray-400'" x-text="guestsLabel"></span>
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
                            <button type="button" @click="selectedGuestsVal = @js($gVal); guestsOpen = false"
                                class="w-full px-4 py-2.5 text-left text-sm hover:bg-gray-50 flex items-center justify-between transition-colors"
                                :class="selectedGuestsVal === @js($gVal) ? 'font-semibold text-[var(--color-primary)]' : 'text-gray-700'">
                                <span>{{ $gLbl }}</span>
                                <span x-show="selectedGuestsVal === @js($gVal)">{!! $checkmarkSvg !!}</span>
                            </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Nút tìm kiếm --}}
                    <div class="flex items-center px-3 shrink-0">
                        <button type="button"
                            x-on:click.stop.prevent="submitSearch()"
                            class="w-12 h-12 bg-[var(--color-primary)] hover:opacity-90 text-white rounded-full
                            transition-all shadow-md hover:shadow-lg active:scale-95 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                @include('bladethemev1::livewire.hero-section._mobile-steps', ['boxSuffix' => 'Banner', 'searchAction' => 'submitSearch()'])

                </div>

            </div>
