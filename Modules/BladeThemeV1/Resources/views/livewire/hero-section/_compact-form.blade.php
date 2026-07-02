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
