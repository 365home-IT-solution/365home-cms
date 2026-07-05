{{--
    Mobile step-by-step search (kiểu Airbnb): Địa điểm -> Thời gian -> Khách.
    Mỗi bước có hàng tiêu đề 1 dòng (nhãn trái, giá trị phải) — bấm vào mới mở rộng nội dung
    bên dưới. Bước cuối (Khách) gộp luôn nút tìm kiếm thay vì có nút tìm kiếm riêng như bản desktop.

    Include từ _banner-form.blade.php và _compact-form.blade.php — kế thừa:
    $locations, $selectedLocation, $locationLabel, $selectedBuoi,
    $guestOpts, $selectedGuests, $guestsLabel, $checkmarkSvg, $checkIn, $checkOut, $searchAction

    Ngoài ra dùng các state Alpine đã khai báo ở x-data cha (banner-form/compact-form/hero-section):
    locationSearch, selectedLocationSlug, mobileLocations — phục vụ tìm kiếm địa điểm client-side
    không cần round-trip Livewire mỗi lần gõ chữ.

    $boxSuffix: chuỗi (VD: 'Banner' / 'Compact') để wire:key không trùng nhau giữa
    hai bản banner-form và compact-form (cùng render trong 1 Livewire component).
--}}
@php
    $keyPrefix = 'mobile-' . \Illuminate\Support\Str::slug($boxSuffix ?: 'main') . '-';
@endphp
<div class="lg:hidden">
<div class="space-y-3">

    {{-- Bước 1: Địa điểm --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 1 ? 0 : 1)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <span class="text-base font-bold text-gray-900 shrink-0">Địa điểm</span>
            <div class="flex items-center gap-2 min-w-0">
                <span class="text-base font-medium truncate {{ $selectedLocation ? 'text-gray-900' : 'text-gray-400' }}">{{ $locationLabel }}</span>
                <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 1 ? 'rotate-180' : ''"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </button>
        <div x-show="mobileStep === 1" x-cloak
            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            class="px-3 pb-3">

            {{-- Ô tìm kiếm điểm đến --}}
            <div class="relative mb-4">
                <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" x-model="locationSearch" placeholder="Tìm kiếm điểm đến"
                    class="w-full pl-11 pr-4 py-3.5 border border-gray-200 rounded-2xl text-base text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
            </div>

            <button type="button" @click="locateMe()" :disabled="locating"
                class="w-full px-4 py-3.5 text-left text-base rounded-2xl hover:bg-gray-50 flex items-center gap-3 text-teal-700 font-semibold transition-colors border-b border-gray-100 mb-1 disabled:opacity-60">
                <svg x-show="!locating" class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <svg x-show="locating" x-cloak class="w-5 h-5 shrink-0 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V2.5"/>
                </svg>
                <span x-text="locating ? 'Đang định vị...' : 'Lân cận'"></span>
            </button>

            <button type="button" wire:click.stop="setLocation('')" @click="selectedLocationSlug = ''; mobileStep = 2"
                wire:key="{{ $keyPrefix }}location-all"
                class="w-full px-4 py-3.5 text-left text-base rounded-2xl hover:bg-gray-50 flex items-center justify-between transition-colors"
                :class="selectedLocationSlug === '' ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700'">
                <span>Tất cả địa điểm</span>
                <svg x-show="selectedLocationSlug === ''" class="w-5 h-5 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
            </button>

            <p class="text-sm font-semibold text-gray-500 mt-3 mb-1 px-4">Điểm đến được đề xuất</p>

            <template x-for="loc in mobileLocations.filter(l => !locationSearch.trim() || l.name.toLowerCase().includes(locationSearch.trim().toLowerCase()))" :key="loc.slug">
                <button type="button" @click="selectedLocationSlug = loc.slug; $wire.setLocation(loc.slug); mobileStep = 2"
                    class="w-full px-4 py-3.5 text-left text-base rounded-2xl hover:bg-gray-50 flex items-center justify-between transition-colors"
                    :class="selectedLocationSlug === loc.slug ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700'">
                    <span x-text="loc.name"></span>
                    <svg x-show="selectedLocationSlug === loc.slug" class="w-5 h-5 text-teal-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                </button>
            </template>
            <p class="text-base text-gray-400 text-center py-4"
                x-show="locationSearch.trim() && !mobileLocations.some(l => l.name.toLowerCase().includes(locationSearch.trim().toLowerCase()))">
                Không tìm thấy địa điểm phù hợp
            </p>
        </div>
    </div>

    {{-- Bước 2: Thời gian --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 2 ? 0 : 2)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <span class="text-base font-bold text-gray-900 shrink-0">Thời gian</span>
            <div class="flex items-center gap-2 min-w-0">
                <span class="text-base font-medium truncate"
                    :class="checkIn ? 'text-gray-900' : 'text-gray-400'"
                    x-text="(checkIn && checkOut) ? (displayCheckIn + ' → ' + displayCheckOut) : '{{ ($checkIn && $checkOut) ? $checkIn . ' → ' . $checkOut : 'Thêm ngày' }}'"></span>
                <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 2 ? 'rotate-180' : ''"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </button>

        <div x-show="mobileStep === 2" x-cloak class="px-3 pb-3">

            {{-- Tabs Theo giờ / Theo ngày --}}
            <div class="flex items-center gap-1.5 mb-3 p-1 bg-gray-100 rounded-full w-fit mx-auto">
                <button type="button" wire:click.stop="setBuoi('1')" @click="dayMode = false; if (checkIn) { checkOut = checkIn }"
                    class="px-4 py-1.5 rounded-full text-xs font-semibold transition-colors {{ $selectedBuoi !== '2' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500' }}">
                    Theo giờ
                </button>
                <button type="button" wire:click.stop="setBuoi('2')" @click="dayMode = true"
                    class="px-4 py-1.5 rounded-full text-xs font-semibold transition-colors {{ $selectedBuoi === '2' ? 'bg-white text-teal-700 shadow-sm' : 'text-gray-500' }}">
                    Theo ngày
                </button>
            </div>

            {{-- Lịch (1 tháng, điều hướng bằng mũi tên) --}}
            <div class="flex items-center justify-between mb-1.5">
                <button type="button" @click="prevMonth()" class="p-1 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="text-[13px] font-semibold text-gray-700" x-text="viewMonthName + ' ' + viewYear"></span>
                <button type="button" @click="nextMonth()" class="p-1 rounded-full hover:bg-gray-100 text-gray-500 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <div class="grid grid-cols-7">
                <template x-for="d in ['CN','T2','T3','T4','T5','T6','T7']">
                    <div class="text-center text-[10px] font-medium text-gray-400" x-text="d"></div>
                </template>
            </div>
            <div class="grid grid-cols-7">
                <template x-for="(date, idx) in getCalendarDays(viewYear, viewMonth)" :key="idx">
                    <div class="flex items-center justify-center aspect-square">
                        <template x-if="date !== null">
                            <button type="button" @click="{{ $selectedBuoi === '2' ? 'selectDate(date)' : 'selectSingleDate(date)' }}" :disabled="isPast(date)"
                                :class="{
                                    'bg-teal-800 text-white rounded-full': isSelected(date),
                                    'bg-teal-50 rounded-none': isInRange(date) && !isSelected(date),
                                    'rounded-l-full': isRangeStart(date) && checkOut,
                                    'rounded-r-full': isRangeEnd(date),
                                    'text-gray-300 cursor-not-allowed pointer-events-none': isPast(date),
                                    'hover:bg-gray-100 cursor-pointer': !isPast(date) && !isSelected(date),
                                }"
                                class="w-full h-full max-w-[30px] max-h-[30px] text-[12px] font-medium flex items-center justify-center transition-colors"
                                x-text="date.getDate()"></button>
                        </template>
                    </div>
                </template>
            </div>

            @if($selectedBuoi !== '2')
            {{-- Badge giờ nhận / trả phòng (chỉ hiện ở tab Theo giờ) --}}
            <div class="mt-2.5 pt-2.5 border-t border-gray-100 space-y-2.5">
                <div>
                    <p class="text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Giờ nhận phòng</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="h in hourBadges" :key="'in-' + h">
                            <button type="button" x-show="availableStartHourBadges.includes(h)"
                                @click="checkInHour = h"
                                :class="checkInHour === h ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200'"
                                class="px-2.5 py-1 rounded-full border text-[11px] font-semibold transition-colors"
                                x-text="String(h).padStart(2,'0') + ':00'"></button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Giờ trả phòng</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="h in hourBadges" :key="'out-' + h">
                            <button type="button" x-show="availableEndHourBadges.includes(h)"
                                @click="checkOutHour = h"
                                :class="checkOutHour === h ? 'bg-teal-700 text-white border-teal-700' : 'bg-white text-gray-600 border-gray-200'"
                                class="px-2.5 py-1 rounded-full border text-[11px] font-semibold transition-colors"
                                x-text="String(h).padStart(2,'0') + ':00'"></button>
                        </template>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex items-center gap-2 mt-2.5">
                <button type="button" @click="resetDate()"
                    :disabled="!checkIn"
                    :class="checkIn ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'border-gray-200 text-gray-300 cursor-not-allowed'"
                    class="flex-1 py-2 border rounded-xl text-sm font-semibold transition-colors">
                    Đặt lại
                </button>
                <button type="button" @click="confirm(); mobileStep = 3"
                    :disabled="!checkIn"
                    :class="checkIn ? 'bg-teal-700 hover:bg-teal-800' : 'bg-gray-200 cursor-not-allowed'"
                    class="flex-1 py-2 text-white rounded-xl text-sm font-semibold transition-colors shadow-sm">
                    Xác nhận
                </button>
            </div>
        </div>
    </div>

    {{-- Bước 3: Khách --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 3 ? 0 : 3)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <span class="text-base font-bold text-gray-900 shrink-0">Khách</span>
            <div class="flex items-center gap-2 min-w-0">
                <span class="text-base font-medium truncate {{ $selectedGuests ? 'text-gray-900' : 'text-gray-400' }}">{{ $guestsLabel }}</span>
                <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 3 ? 'rotate-180' : ''"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </button>
        <div x-show="mobileStep === 3" x-cloak class="px-2 pb-3">
            @foreach($guestOpts as $gVal => $gLbl)
            <button type="button" wire:click.stop="setGuests(@js($gVal))" @click="mobileStep = 0"
                wire:key="{{ $keyPrefix }}guests-{{ $gVal ?: 'all' }}"
                class="w-full px-4 py-3.5 text-left text-base rounded-2xl hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal) ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700' }}">
                <span>{{ $gLbl }}</span>
                @if((!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal)) {!! $checkmarkSvg !!} @endif
            </button>
            @endforeach
        </div>
    </div>

    {{-- Xoá tất cả + Tìm kiếm — luôn hiển thị bất kể đang ở bước nào (giống Airbnb),
         không phụ thuộc vào việc bước Khách có đang mở hay không. --}}
    <div class="flex items-center gap-3 pt-1">
        <button type="button" wire:click.stop="clearAll()"
            @click="checkIn = null; checkOut = null; selectedLocationSlug = ''; locationSearch = ''; mobileStep = 1"
            class="flex-1 py-3.5 rounded-2xl border-2 border-gray-200 text-gray-700 text-base font-semibold text-center hover:bg-gray-50 active:scale-[0.98] transition-all">
            Xoá tất cả
        </button>
        <button type="button" x-on:click.stop.prevent="{{ $searchAction }}"
            class="flex-1 py-3.5 rounded-2xl bg-teal-600 hover:bg-teal-700 text-white text-base font-bold text-center shadow-md hover:shadow-lg active:scale-[0.98] transition-all flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            Tìm kiếm
        </button>
    </div>

</div>
</div>
