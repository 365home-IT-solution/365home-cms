{{--
    Mobile step-by-step search (kiểu Airbnb): Địa điểm -> Ngày & giờ -> Loại đặt -> Số người.
    Mỗi bước chọn xong tự chuyển sang bước kế tiếp; bước cuối (Số người) gộp luôn nút tìm kiếm
    thay vì có nút tìm kiếm riêng như bản desktop.

    Include từ _banner-form.blade.php và _compact-form.blade.php — kế thừa:
    $locations, $selectedLocation, $locationLabel, $buoiOpts, $selectedBuoi, $buoiLabel,
    $guestOpts, $selectedGuests, $guestsLabel, $checkmarkSvg, $checkIn, $checkOut, $searchAction

    $boxSuffix: chuỗi (VD: 'Banner' / 'Compact') để x-ref và wire:key không trùng nhau giữa
    hai bản banner-form và compact-form (cùng render trong 1 Livewire component).
--}}
@php
    $keyPrefix = 'mobile-' . \Illuminate\Support\Str::slug($boxSuffix ?: 'main') . '-';
@endphp
<div class="md:hidden" style="padding-bottom:76px;">
<div class="space-y-3">

    {{-- Bước 1: Địa điểm --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 1 ? 0 : 1)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <div class="min-w-0">
                <span class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Địa điểm</span>
                <span class="block text-sm font-semibold mt-0.5 truncate {{ $selectedLocation ? 'text-gray-900' : 'text-gray-400' }}">{{ $locationLabel }}</span>
            </div>
            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 1 ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="mobileStep === 1" x-cloak
            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            class="px-2 pb-3">
            <button type="button" @click="locateMe()" :disabled="locating"
                class="w-full px-3 py-2.5 text-left text-sm rounded-xl hover:bg-gray-50 flex items-center gap-2 text-teal-700 font-semibold transition-colors border-b border-gray-100 mb-1 disabled:opacity-60">
                <svg x-show="!locating" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <svg x-show="locating" x-cloak class="w-4 h-4 shrink-0 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V2.5"/>
                </svg>
                <span x-text="locating ? 'Đang định vị...' : 'Vị trí của tôi'"></span>
            </button>
            <button type="button" wire:click.stop="setLocation('')" @click="mobileStep = 2"
                wire:key="{{ $keyPrefix }}location-all"
                class="w-full px-3 py-2.5 text-left text-sm rounded-xl hover:bg-gray-50 flex items-center justify-between transition-colors {{ !$selectedLocation ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700' }}">
                <span>Tất cả địa điểm</span>
                @if(!$selectedLocation) {!! $checkmarkSvg !!} @endif
            </button>
            @foreach($locations as $loc)
            <button type="button" wire:click.stop="setLocation(@js($loc['slug']))" @click="mobileStep = 2"
                wire:key="{{ $keyPrefix }}location-{{ $loc['slug'] }}"
                class="w-full px-3 py-2.5 text-left text-sm rounded-xl hover:bg-gray-50 flex items-center justify-between transition-colors {{ $selectedLocation === $loc['slug'] ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700' }}">
                <span>{{ $loc['name'] }}</span>
                @if($selectedLocation === $loc['slug']) {!! $checkmarkSvg !!} @endif
            </button>
            @endforeach
        </div>
    </div>

    {{-- Bước 2: Ngày & giờ --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 2 ? 0 : 2)"
            class="w-full px-4 py-3.5 flex items-center gap-3 text-left">
            <div class="flex-1 min-w-0">
                <span class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Ngày &amp; giờ</span>
                <span class="block text-sm font-semibold mt-0.5 truncate"
                    :class="checkIn ? 'text-gray-900' : 'text-gray-400'"
                    x-text="(checkIn && checkOut) ? (displayCheckIn + ' → ' + displayCheckOut) : '{{ ($checkIn && $checkOut) ? $checkIn . ' → ' . $checkOut : 'Chọn ngày & giờ' }}'"></span>
            </div>
            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 2 ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="mobileStep === 2" x-cloak class="px-3 pb-3">
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
                            <button type="button" @click="selectDate(date)" :disabled="isPast(date)"
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

            {{-- Giờ nhận / trả phòng — dùng select gọn, không cần dropdown/scroll riêng --}}
            <div class="grid grid-cols-2 gap-2 mt-2 pt-2 border-t border-gray-100">
                {{-- Check-in --}}
                <div>
                    <p class="text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1">Nhận phòng</p>
                    <div class="flex items-center gap-1">
                        <select x-model.number="checkInHour"
                            class="flex-1 min-w-0 border border-gray-200 rounded-lg px-1 py-1.5 text-[12px] font-semibold text-gray-900 bg-white">
                            <template x-for="h in availableCheckInHours" :key="h">
                                <option :value="h" x-text="String(h).padStart(2,'0') + 'h'"></option>
                            </template>
                        </select>
                        <select x-model.number="checkInMin"
                            class="flex-1 min-w-0 border border-gray-200 rounded-lg px-1 py-1.5 text-[12px] font-semibold text-gray-900 bg-white">
                            <template x-for="m in availableCheckInMinutes" :key="m">
                                <option :value="m" x-text="String(m).padStart(2,'0')"></option>
                            </template>
                        </select>
                    </div>
                </div>
                {{-- Check-out --}}
                <div>
                    <p class="text-[9px] font-bold text-gray-500 uppercase tracking-wider mb-1 truncate">
                        Trả phòng
                        <span x-show="isSameDayBooking" class="text-teal-600 normal-case font-normal">(cùng ngày)</span>
                    </p>
                    <div class="flex items-center gap-1">
                        <select x-model.number="checkOutHour" @change="ensureCheckOutAfterCheckIn()"
                            class="flex-1 min-w-0 border border-gray-200 rounded-lg px-1 py-1.5 text-[12px] font-semibold text-gray-900 bg-white">
                            <template x-for="h in availableCheckoutHours" :key="h">
                                <option :value="h" x-text="String(h).padStart(2,'0') + 'h'"></option>
                            </template>
                        </select>
                        <select x-model.number="checkOutMin" @change="ensureCheckOutAfterCheckIn()"
                            class="flex-1 min-w-0 border border-gray-200 rounded-lg px-1 py-1.5 text-[12px] font-semibold text-gray-900 bg-white">
                            <template x-for="m in (checkIn && checkOut && isSameDay(checkIn, checkOut) && checkOutHour === checkInHour ? minutes.filter(m => m > checkInMin) : minutes)" :key="m">
                                <option :value="m" x-text="String(m).padStart(2,'0')"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

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

    {{-- Bước 3: Loại đặt --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 3 ? 0 : 3)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <div class="min-w-0">
                <span class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Loại đặt</span>
                <span class="block text-sm font-semibold mt-0.5 {{ $selectedBuoi ? 'text-gray-900' : 'text-gray-400' }}">{{ $buoiLabel }}</span>
            </div>
            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 3 ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="mobileStep === 3" x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="px-3 pb-4 space-y-2.5">
            @php
                $buoiMeta = [
                    ''  => ['icon' => 'grid', 'desc' => 'Xem tất cả loại phòng đang có'],
                    '1' => ['icon' => 'clock', 'desc' => 'Đặt theo từng khung giờ ngắn'],
                    '2' => ['icon' => 'calendar', 'desc' => 'Đặt trọn ngày, nhận – trả phòng cố định'],
                ];
            @endphp
            @foreach($buoiOpts as $bVal => $bLbl)
            @php
                $isSel = (!$bVal && !$selectedBuoi) || ($bVal && $selectedBuoi === $bVal);
                $bIcon = $buoiMeta[$bVal]['icon'] ?? 'grid';
                $bDesc = $buoiMeta[$bVal]['desc'] ?? '';
            @endphp
            <button type="button" wire:click.stop="setBuoi(@js($bVal))" @click="mobileStep = 4"
                wire:key="{{ $keyPrefix }}buoi-{{ $bVal ?: 'all' }}"
                class="w-full flex items-center gap-3 p-3 rounded-2xl border-2 text-left transition-all duration-200 active:scale-[0.98] {{ $isSel ? 'border-teal-600 bg-teal-50/70 shadow-sm' : 'border-gray-100 bg-white hover:border-gray-200 hover:bg-gray-50' }}">
                <span class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-colors duration-200 {{ $isSel ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-500' }}">
                    @if($bIcon === 'clock')
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @elseif($bIcon === 'calendar')
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    @else
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    @endif
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold {{ $isSel ? 'text-teal-800' : 'text-gray-900' }}">{{ $bLbl }}</span>
                    <span class="block text-xs text-gray-500 mt-0.5">{{ $bDesc }}</span>
                </span>
                <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-colors duration-200 {{ $isSel ? 'border-teal-600 bg-teal-600' : 'border-gray-300' }}">
                    @if($isSel)
                    <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    @endif
                </span>
            </button>
            @endforeach
        </div>
    </div>

    {{-- Bước 4: Số người --}}
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <button type="button" @click="mobileStep = (mobileStep === 4 ? 0 : 4)"
            class="w-full px-4 py-3.5 flex items-center justify-between gap-3 text-left">
            <div class="min-w-0">
                <span class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Số người</span>
                <span class="block text-sm font-semibold mt-0.5 {{ $selectedGuests ? 'text-gray-900' : 'text-gray-400' }}">{{ $guestsLabel }}</span>
            </div>
            <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-200" :class="mobileStep === 4 ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="mobileStep === 4" x-cloak class="px-2 pb-3">
            @foreach($guestOpts as $gVal => $gLbl)
            <button type="button" wire:click.stop="setGuests(@js($gVal))" @click="mobileStep = 0"
                wire:key="{{ $keyPrefix }}guests-{{ $gVal ?: 'all' }}"
                class="w-full px-3 py-2.5 text-left text-sm rounded-xl hover:bg-gray-50 flex items-center justify-between transition-colors {{ (!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal) ? 'font-semibold text-teal-700 bg-teal-50/60' : 'text-gray-700' }}">
                <span>{{ $gLbl }}</span>
                @if((!$gVal && !$selectedGuests) || ($gVal && $selectedGuests === $gVal)) {!! $checkmarkSvg !!} @endif
            </button>
            @endforeach
        </div>
    </div>


</div>
</div>

{{-- Hàng cuối: Xoá tất cả (trái) + Tìm kiếm (phải) — luôn cố định dưới cùng màn hình trên
     mobile, để lúc nào cũng bấm tìm kiếm được dù chưa chỉnh xong hết các bước --}}
<div class="md:hidden" style="position:fixed; left:0; right:0; bottom:0; z-index:20; background:#fff; border-top:1px solid #f0f0f0; box-shadow:0 -4px 16px rgba(0,0,0,.08); padding:12px 16px calc(12px + env(safe-area-inset-bottom));">
    <div class="flex items-center gap-3">
        <button type="button" wire:click.stop="clearAll()"
            @click="checkIn = null; checkOut = null; mobileStep = 1"
            class="flex-1 py-3 rounded-2xl border-2 border-gray-200 text-gray-700 text-sm font-semibold text-center hover:bg-gray-50 active:scale-[0.98] transition-all">
            Xoá tất cả
        </button>
        <button type="button" x-on:click.stop.prevent="{{ $searchAction }}"
            class="flex-1 py-3 rounded-2xl bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold text-center shadow-md hover:shadow-lg active:scale-[0.98] transition-all flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            Tìm kiếm
        </button>
    </div>
</div>
