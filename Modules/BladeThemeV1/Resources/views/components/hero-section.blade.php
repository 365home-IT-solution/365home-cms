<script>
    if (typeof window.heroDatePicker === 'undefined') {
        window.heroDatePicker = function () {
            return {
                open: false,
                checkIn: null,
                checkOut: null,
                hoverDate: null,
                checkInHour: 14,
                checkInMin: 0,
                checkOutHour: 12,
                checkOutMin: 0,
                viewMonth: null,
                viewYear: null,
                monthNames: ['Tháng 1','Tháng 2','Tháng 3','Tháng 4','Tháng 5','Tháng 6',
                             'Tháng 7','Tháng 8','Tháng 9','Tháng 10','Tháng 11','Tháng 12'],
                hours: Array.from({length: 24}, (_, i) => i),
                minutes: [0, 15, 30, 45],
                init() {
                    const now = new Date();
                    this.viewMonth = now.getMonth();
                    this.viewYear  = now.getFullYear();
                    // Mặc định hôm nay cho cả hai → isSameDayBooking = true ngay từ đầu
                    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    this.checkIn  = today;
                    this.checkOut = today;
                    const correct = () => this.$nextTick(() => this.ensureCheckOutAfterCheckIn());
                    this.$watch('checkInHour',  correct);
                    this.$watch('checkInMin',   correct);
                    this.$watch('checkOut',     correct);
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
                    for (let d = 1; d <= this.getDaysInMonth(year, month); d++) {
                        days.push(new Date(year, month, d));
                    }
                    return days;
                },
                selectDate(date) {
                    if (!date || this.isPast(date)) return;
                    if (!this.checkIn) {
                        // Chưa chọn → đặt cả nhận + trả cùng ngày
                        this.checkIn = date; this.checkOut = date;
                    } else if (this.checkIn && this.checkOut && !this.isSameDay(this.checkIn, this.checkOut)) {
                        // Đang có range khác ngày → reset về cùng ngày mới
                        this.checkIn = date; this.checkOut = date;
                    } else {
                        // Đang ở chế độ cùng ngày → click ngày khác để mở range
                        if (this.isSameDay(date, this.checkIn)) {
                            // Click lại cùng ngày → giữ nguyên
                        } else if (date < this.checkIn) {
                            this.checkOut = this.checkIn; this.checkIn = date;
                        } else {
                            this.checkOut = date;
                        }
                    }
                },
                isPast(date) {
                    const today = new Date(); today.setHours(0,0,0,0);
                    return date < today;
                },
                isSameDay(a, b) {
                    return a && b && a.getFullYear()===b.getFullYear() && a.getMonth()===b.getMonth() && a.getDate()===b.getDate();
                },
                isSelected(date) {
                    return date && (this.isSameDay(date, this.checkIn) || this.isSameDay(date, this.checkOut));
                },
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
                get displayCheckIn() {
                    if (!this.checkIn) return '';
                    return `${this.formatDisplay(this.checkIn)} ${String(this.checkInHour).padStart(2,'0')}:${String(this.checkInMin).padStart(2,'0')}`;
                },
                get displayCheckOut() {
                    // Cùng ngày → trả phòng dùng ngày checkIn
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
                        if (nxt !== undefined) { newM = nxt; }
                        else { newH = Math.min(inH + 1, 23); newM = 0; }
                    } else if (outH === inH && outM <= inM) {
                        const nxt = this.minutes.find(m => m > inM);
                        if (nxt !== undefined) { newM = nxt; }
                        else { newH = Math.min(inH + 1, 23); newM = 0; }
                    }
                    if (newH !== this.checkOutHour) this.checkOutHour = newH;
                    if (newM !== this.checkOutMin)  this.checkOutMin  = newM;
                },
                // Rebuild innerHTML trực tiếp → disabled attribute trong HTML string, không dùng Alpine binding
                buildCheckoutHourHtml() {
                    const same = this.isSameDayBooking;
                    const inH  = this.checkInHour;
                    return Array.from({length: 24}, (_, h) => {
                        const dis   = same && h < inH;
                        const label = String(h).padStart(2, '0');
                        return `<option value="${h}"${dis ? ' disabled style="color:#9ca3af;background-color:#f3f4f6"' : ''}>${label}</option>`;
                    }).join('');
                },
                buildCheckoutMinHtml() {
                    const same = this.isSameDayBooking;
                    const inH  = this.checkInHour;
                    const inM  = this.checkInMin;
                    const outH = this.checkOutHour;
                    return [0, 15, 30, 45].map(m => {
                        const dis   = same && outH === inH && m <= inM;
                        const label = String(m).padStart(2, '0');
                        return `<option value="${m}"${dis ? ' disabled style="color:#9ca3af;background-color:#f3f4f6"' : ''}>${label}</option>`;
                    }).join('');
                },
                confirm() { this.open = false; },
                cancel() { this.open = false; },
            };
        };
    }
</script>

<section class="relative flex flex-col justify-end overflow-hidden">

    {{-- Banner image --}}
    <div class="absolute inset-0 bg-cover bg-center bg-no-repeat"
        style="background-image: url('https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1920&q=80')">
    </div>

    {{-- Dark gradient overlay --}}
    <div class="absolute inset-0"
        style="background: linear-gradient(to bottom, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0.15) 40%, rgba(0,0,0,0.60) 100%)">
    </div>

    {{-- Content --}}
    <div class="relative z-10 w-full max-w-6xl mx-auto px-4 sm:px-6 pb-16 pt-28">

        {{-- Booking Form --}}
        <div x-data="heroDatePicker()" class="rounded-2xl shadow-2xl p-6 md:p-8">

            {{-- Room type tabs --}}
            <div x-data="{ activeTab: 'all' }" class="mb-6">
                <div class="flex flex-wrap gap-2">
                    @foreach([
                        ['key'=>'all',      'label'=>'Tất cả'],
                        ['key'=>'villa',    'label'=>'VILLA'],
                        ['key'=>'chung-cu', 'label'=>'CHUNG CƯ'],
                        ['key'=>'home',     'label'=>'HOME'],
                        ['key'=>'nha-nghi', 'label'=>'NHÀ NGHỈ'],
                        ['key'=>'hotel',    'label'=>'HOTEL'],
                    ] as $tab)
                    <button
                        @click="activeTab = '{{ $tab['key'] }}'"
                        :class="activeTab === '{{ $tab['key'] }}' ?
                            'bg-teal-800 text-white border-teal-800/60 shadow-md' :
                            'bg-white/10 text-white/80 border-white/25 hover:bg-white/20 hover:text-white'"
                        class="px-5 py-2 rounded-full text-sm font-semibold border backdrop-blur-sm transition-all duration-200">
                        {{ $tab['label'] }}
                    </button>
                    @endforeach
                </div>
            </div>

            {{-- Form fields — tất cả trên 1 hàng --}}
            <div class="flex items-end w-full">

                {{-- Địa điểm --}}
                <div class="flex flex-col gap-1.5 flex-[3] min-w-0">
                    <label class="text-xs font-bold text-white/90 uppercase tracking-widest pl-1">Địa điểm</label>
                    <div class="relative">
                        <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-500 pointer-events-none"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <select class="w-full h-14 pl-10 pr-3 border border-gray-200 rounded-l-2xl border-r-0
                            text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500
                            appearance-none cursor-pointer">
                            <option value="">Chọn địa điểm</option>
                            <option>Cần Thơ</option>
                            <option>Hồ Chí Minh</option>
                            <option>Đà Nẵng</option>
                            <option>Nha Trang</option>
                            <option>Phú Quốc</option>
                        </select>
                    </div>
                </div>

                {{-- Thời gian --}}
                <div class="flex flex-col gap-1.5 flex-[4] min-w-0">
                    <label class="text-xs font-bold text-white/90 uppercase tracking-widest pl-1">Thời gian</label>
                    <button @click="open = true"
                        class="w-full h-14 flex items-center justify-between px-4 border border-gray-200 border-r-0
                            bg-white hover:border-teal-500 hover:shadow-sm transition-all text-sm text-left">
                        <div class="flex flex-col min-w-0 gap-0.5">
                            <span class="text-[10px] font-medium text-gray-400 leading-none uppercase tracking-wide">Nhận phòng</span>
                            <span class="font-semibold text-gray-800 text-xs truncate"
                                x-text="displayCheckIn || '--/--/----'"></span>
                        </div>
                        <div class="w-px h-8 bg-gray-200 mx-3 shrink-0"></div>
                        <div class="flex flex-col min-w-0 gap-0.5">
                            <span class="text-[10px] font-medium text-gray-400 leading-none uppercase tracking-wide">Trả phòng</span>
                            <span class="font-semibold text-gray-800 text-xs truncate"
                                x-text="displayCheckOut || '--/--/----'"></span>
                        </div>
                        <svg class="w-4 h-4 text-teal-500 ml-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </button>
                </div>

                {{-- Loại phòng: 1 = theo giờ, 2 = theo ngày (cột styles trong bảng products) --}}
                <div class="flex flex-col gap-1.5 flex-[2] min-w-0">
                    <label class="text-xs font-bold text-white/90 uppercase tracking-widest pl-1">Loại phòng</label>
                    <div class="relative">
                        <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-500 pointer-events-none"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                        </svg>
                        <select class="w-full h-14 pl-10 pr-2 border border-gray-200 border-r-0
                            text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500
                            appearance-none cursor-pointer">
                            <option value="">Chọn loại đặt</option>
                            <option value="1">Theo giờ</option>
                            <option value="2">Theo ngày</option>
                        </select>
                    </div>
                </div>

                {{-- Số người --}}
                <div class="flex flex-col gap-1.5 flex-[2] min-w-0">
                    <label class="text-xs font-bold text-white/90 uppercase tracking-widest pl-1">Số người</label>
                    <div class="relative">
                        <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-500 pointer-events-none"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <select class="w-full h-14 pl-10 pr-2 border border-gray-200 border-r-0
                            text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500
                            appearance-none cursor-pointer">
                            <option value="">Số người</option>
                            <option>1 người</option>
                            <option>2 người</option>
                            <option>3 người</option>
                            <option>4 người</option>
                            <option>5+ người</option>
                        </select>
                    </div>
                </div>

                {{-- Tìm kiếm --}}
                <div class="flex flex-col gap-1.5 shrink-0">
                    <label class="text-xs font-bold text-transparent select-none">_</label>
                    <button class="h-14 px-7 bg-teal-600 hover:bg-teal-800 text-white font-bold rounded-r-2xl
                        transition-all shadow-lg hover:shadow-xl active:scale-[0.98] flex items-center justify-center gap-2.5
                        text-sm whitespace-nowrap">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <iconify-icon icon="ph:arrow-right-bold" stroke-width="1.5"></iconify-icon>
                    </button>
                </div>

            </div>

            {{-- Date Picker Modal --}}
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 x-cloak
                 class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
                 @click.self="cancel()">

                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-y-auto max-h-screen py-6 px-4 md:px-8"
                    @click.stop>

                    <div class="flex items-center justify-between mb-5">
                        <h3 class="text-lg font-semibold text-gray-800">Chọn ngày & giờ</h3>
                        <button @click="cancel()" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="flex items-center justify-between mb-4">
                        <button @click="prevMonth()" class="p-2 rounded-lg hover:bg-gray-100 text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <div class="flex-1 grid grid-cols-2 gap-4 text-center text-sm font-semibold text-gray-700">
                            <span x-text="viewMonthName + ' ' + viewYear"></span>
                            <span x-text="nextViewMonthName + ' ' + nextViewYear"></span>
                        </div>
                        <button @click="nextMonth()" class="p-2 rounded-lg hover:bg-gray-100 text-gray-600 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                                            <button @click="selectDate(date)" @mouseenter="hoverDate = date"
                                                @mouseleave="hoverDate = null" :disabled="isPast(date)"
                                                :class="{
                                                    'bg-teal-800 text-white rounded-full': isSelected(date),
                                                    'bg-teal-100 rounded-none': isInRange(date) && !isSelected(date),
                                                    'rounded-l-full': isRangeStart(date) && checkOut,
                                                    'rounded-r-full': isRangeEnd(date),
                                                    'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                                    'hover:bg-teal-50 cursor-pointer': !isPast(date) && !isSelected(date),
                                                }"
                                                class="w-8 h-8 text-sm flex items-center justify-center transition-colors"
                                                x-text="date.getDate()">
                                            </button>
                                        </template>
                                        <template x-if="date === null">
                                            <div class="w-8 h-8"></div>
                                        </template>
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
                                            <button @click="selectDate(date)" @mouseenter="hoverDate = date"
                                                @mouseleave="hoverDate = null" :disabled="isPast(date)"
                                                :class="{
                                                    'bg-teal-800 text-white rounded-full': isSelected(date),
                                                    'bg-teal-100 rounded-none': isInRange(date) && !isSelected(date),
                                                    'rounded-l-full': isRangeStart(date) && checkOut,
                                                    'rounded-r-full': isRangeEnd(date),
                                                    'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                                    'hover:bg-teal-50 cursor-pointer': !isPast(date) && !isSelected(date),
                                                }"
                                                class="w-8 h-8 text-sm flex items-center justify-center transition-colors"
                                                x-text="date.getDate()">
                                            </button>
                                        </template>
                                        <template x-if="date === null">
                                            <div class="w-8 h-8"></div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mt-6 pt-5 border-t border-gray-100">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Giờ Check-in</p>
                            <div class="flex gap-2 items-center">
                                <select x-model.number="checkInHour"
                                        class="flex-1 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <template x-for="h in hours" :key="h">
                                        <option :value="h" x-text="String(h).padStart(2,'0')"></option>
                                    </template>
                                </select>
                                <span class="text-gray-400">:</span>
                                <select x-model.number="checkInMin"
                                        class="flex-1 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <template x-for="m in minutes" :key="m">
                                        <option :value="m" x-text="String(m).padStart(2,'0')"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                                Giờ Check-out
                                <span x-show="isSameDayBooking" class="text-teal-600 normal-case font-normal">(cùng ngày)</span>
                            </p>
                            <div class="flex gap-2 items-center">
                                <select x-model.number="checkOutHour"
                                        @change="ensureCheckOutAfterCheckIn(+$event.target.value)"
                                        x-effect="$el.innerHTML = buildCheckoutHourHtml(); $el.value = checkOutHour"
                                        class="flex-1 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                </select>
                                <span class="text-gray-400">:</span>
                                <select x-model.number="checkOutMin"
                                        x-effect="$el.innerHTML = buildCheckoutMinHtml(); $el.value = checkOutMin"
                                        class="flex-1 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 justify-end mt-5 pt-4 border-t border-gray-100">
                        <button @click="cancel()" class="px-5 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-600 hover:bg-gray-50 transition-colors font-medium">
                            Hủy
                        </button>
                        <button @click="confirm()" class="px-5 py-2.5 bg-teal-700 hover:bg-teal-800 text-white rounded-xl text-sm font-semibold transition-colors">
                            Xác nhận
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Stats --}}
        {{-- <div class="mt-10 flex flex-wrap justify-center gap-12">
            <div class="text-center">
                <p class="text-3xl font-bold text-white drop-shadow-lg">200+</p>
                <p class="text-white/75 text-sm mt-1.5 tracking-wide">Phòng sẵn sàng</p>
            </div>
            <div class="text-center">
                <p class="text-3xl font-bold text-white drop-shadow-lg">5.000+</p>
                <p class="text-white/75 text-sm mt-1.5 tracking-wide">Lượt đặt thành công</p>
            </div>
            <div class="text-center">
                <p class="text-3xl font-bold text-white drop-shadow-lg">4.9★</p>
                <p class="text-white/75 text-sm mt-1.5 tracking-wide">Đánh giá trung bình</p>
            </div>
        </div> --}}
    </div>

    {{-- Wave divider --}}
    <div class="absolute bottom-0 left-0 right-0">
        <svg viewBox="0 0 1440 80" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"
            class="w-full h-16">
            <path d="M0 80L1440 80L1440 20C1200 80 960 0 720 20C480 40 240 0 0 20L0 80Z" fill="white"/>
        </svg>
    </div>
</section>
