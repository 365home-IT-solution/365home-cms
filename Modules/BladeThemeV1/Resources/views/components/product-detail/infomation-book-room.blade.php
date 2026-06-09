<div class="bg-white p-2 rounded-none lg:rounded-lg">
    <h2 class="md:text-2xl text-start text-xl font-extrabold mb-2 text-gray-900 transition-all duration-300">
        Thông tin Đặt phòng
    </h2>

    {{-- Alpine.js: tự động prefill từ localStorage khi đã đăng nhập --}}
    <div
        x-data="{}"
        x-init="
            const token = localStorage.getItem('auth_token');
            if (token) $wire.prefillFromAuth(token);
            window.addEventListener('auth-state-changed', () => {
                const t = localStorage.getItem('auth_token');
                $wire.prefillFromAuth(t || '');
            });
        "
    ></div>

    <form wire:submit.prevent="datPhong" class="space-y-5">
        @csrf

        {{-- Style 1: Tóm tắt khung giờ đã chọn từ bảng bên trái --}}
        @if($bookingStyle == 1)
        @if(!empty($selectedSlots))
        @php
        $sorted = collect($selectedSlots)->sortBy('date');
        $firstSlot = $sorted->first();
        $lastSlot = $sorted->last();
        $checkinDt = \Carbon\Carbon::parse($firstSlot['date'] . ' ' . $firstSlot['startTime']);
        $checkoutDt = \Carbon\Carbon::parse($lastSlot['date'] . ' ' . $lastSlot['endTime']);
        // Nếu slot cuối là qua đêm (endTime < startTime), trả phòng là ngày hôm sau
            $lastSlotEndTime=\Carbon\Carbon::parse($lastSlot['endTime']);
            $lastSlotStartTime=\Carbon\Carbon::parse($lastSlot['startTime']); if (!empty($lastSlot['overNight']) ||
            $lastSlotEndTime->lt($lastSlotStartTime)) {
            $checkoutDt->addDay();
            }
            $diffHours = $checkinDt->diffInHours($checkoutDt);
            @endphp
            <div class="rounded-xl p-4" style="background:#f0f4f0; border:2px solid #4e6b4c">
                <h3 class="text-sm font-bold mb-3 flex items-center gap-2" style="color:#4e6b4c">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Thời gian đã chọn
                </h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-white rounded-lg p-3 text-center">
                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Nhận phòng</p>
                        <p class="text-xl font-extrabold text-gray-900">{{ $checkinDt->format('H:i') }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $checkinDt->format('d/m/Y') }}</p>
                    </div>
                    <div class="bg-white rounded-lg p-3 text-center">
                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Trả phòng</p>
                        <p class="text-xl font-extrabold text-gray-900">{{ $checkoutDt->format('H:i') }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $checkoutDt->format('d/m/Y') }}</p>
                    </div>
                </div>
                <p class="text-xs text-center mt-2 font-semibold" style="color:#4e6b4c">
                    {{ count($selectedSlots) }} khung giờ &middot; {{ $diffHours }}h
                </p>
            </div>
            @else
            <div class="rounded-xl p-5 text-center" style="background:#f9fafb; border:2px dashed #d1d5db">
                <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <p class="text-sm text-gray-400">Vui lòng chọn khung giờ<br>từ bảng bên trái</p>
            </div>
            @endif

            {{-- Style 2: Tóm tắt ngày nhận/trả phòng (chỉ hiển thị ngày, không có giờ) --}}
            @elseif($bookingStyle == 2)
            @if(!empty($startTime) && !empty($endTime))
            @php
            $checkinDt2 = \Carbon\Carbon::parse($startTime)->startOfDay();
            $checkoutDt2 = \Carbon\Carbon::parse($endTime)->startOfDay();
            $nights = $checkinDt2->diffInDays($checkoutDt2);
            $style2CheckinTimeDisplay = !empty($style2CheckinTime ?? null) ? $style2CheckinTime : '14:00';
            $style2CheckoutTimeDisplay = !empty($style2CheckoutTime ?? null) ? $style2CheckoutTime : '12:00';
            @endphp
            <div class="rounded-xl p-4" style="background:#f0f4f0; border:2px solid #4e6b4c">
                <h3 class="text-sm font-bold mb-3 flex items-center gap-2" style="color:#4e6b4c">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Thời gian đã chọn
                </h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-white rounded-lg p-3 text-center">
                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Nhận phòng</p>
                        <p class="text-xl font-extrabold text-gray-900">{{ $style2CheckinTimeDisplay }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $checkinDt2->format('d/m/Y') }}</p>
                    </div>
                    <div class="bg-white rounded-lg p-3 text-center">
                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Trả phòng</p>
                        <p class="text-xl font-extrabold text-gray-900">{{ $style2CheckoutTimeDisplay }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $checkoutDt2->format('d/m/Y') }}</p>
                    </div>
                </div>
                <p class="text-xs text-center mt-2 font-semibold" style="color:#4e6b4c">
                    {{ $nights }} đêm
                </p>
                @php
                $dpM = (int)($product->deposit_multi_night ?? 50);
                $minNights = (int)($product->deposit_min_nights ?? 2);
                // Có cọc khi: minNights > 0 VÀ số đêm >= ngưỡng
                $dpPctBase = ($minNights > 0 && $nights >= $minNights) ? $dpM : 100;
                $dpPct = ($paymentOption === 'full') ? 100 : $dpPctBase;
                @endphp
                @if($dpPct < 100) <div
                    class="mt-2 flex items-center justify-center gap-1.5 bg-amber-50 border border-amber-300 rounded-lg px-3 py-1.5">
                    <svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <span class="text-xs text-amber-700 font-semibold">Cọc {{ $dpPct }}% để xác nhận đặt phòng</span>
            </div>
            @else
            <div
                class="mt-2 flex items-center justify-center gap-1.5 bg-green-50 border border-green-300 rounded-lg px-3 py-1.5">
                <svg class="w-3.5 h-3.5 text-green-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-xs text-green-700 font-semibold">Thanh toán 100% — nhận mã cổng ngay</span>
            </div>
            @endif
</div>
@endif
@endif

@php
$canApplyCoupon = $bookingStyle == 2
? (!empty($startTime) && !empty($endTime))
: !empty($selectedSlots);

$bookingServiceTotal = 0;
if (!empty($selectedServices) && $additionalServices) {
foreach ($selectedServices as $svcId => $svcQty) {
$svc = $additionalServices->firstWhere('id', $svcId);
if ($svc && $svcQty > 0) {
$bookingServiceTotal += $svc->price * $svcQty;
}
}
}
@endphp

{{-- Dịch vụ đã chọn --}}
@if($bookingServiceTotal > 0 && $additionalServices)
<div class="space-y-1">
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Dịch vụ thêm</p>
    @foreach($selectedServices as $svcId => $svcQty)
    @php $svc = $additionalServices->firstWhere('id', $svcId); @endphp
    @if($svc && $svcQty > 0)
    <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
        <div class="flex items-center gap-2">
            @if(!empty($svc->image))
            <img src="{{ asset('storage/' . $svc->image) }}" alt="{{ $svc->name }}" class="w-7 h-7 object-contain rounded">
                                                    @else
            <span class="text-base">🍽️</span>
            @endif
            <span class="text-xs font-medium text-gray-700">{{ $svc->name }}</span>
            <span class="text-xs text-gray-400">x{{ $svcQty }}</span>
        </div>
        <span class="text-xs font-semibold" style="color:#4e6b4c">+{{ number_format($svc->price * $svcQty, 0, ',', '.') }}đ</span>
    </div>
    @endif
    @endforeach
</div>
@endif

{{-- Tóm tắt dịch vụ & tổng tiền --}}
@if($totalAmount > 0 || $bookingServiceTotal > 0)
<div class="rounded-xl p-4 relative" style="background:#f0f4f0; border:2px solid #4e6b4c">
    {{-- Loading overlay khi Livewire đang tính lại --}}
    <div wire:loading wire:target="guests"
        class="absolute inset-0 rounded-xl bg-white/70 flex items-center justify-center z-10 backdrop-blur-[1px]">
        <div class="flex items-center gap-2 bg-white rounded-full px-4 py-2 shadow-sm">
            <svg class="animate-spin w-4 h-4" style="color:#4e6b4c" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span class="text-xs font-medium" style="color:#4e6b4c">Đang tính lại...</span>
        </div>
    </div>
    <h3 class="text-sm font-bold mb-3 flex items-center gap-2" style="color:#4e6b4c">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
            <line x1="1" y1="10" x2="23" y2="10" />
        </svg>
        Tóm tắt đơn đặt phòng
    </h3>

    {{-- Tổng tiền + cọc (style=2) --}}
    @if($bookingStyle == 2 && !empty($startTime) && !empty($endTime))
    @php
    $ci2 = \Carbon\Carbon::parse($startTime)->startOfDay();
    $co2 = \Carbon\Carbon::parse($endTime)->startOfDay();
    $n2 = $ci2->diffInDays($co2);
    $dpMv = (int)($product->deposit_multi_night ?? 50);
    $minNights2 = (int)($product->deposit_min_nights ?? 2);
    // Có cọc khi: minNights > 0 VÀ số đêm >= ngưỡng
    $dpPctAuto = ($minNights2 > 0 && $n2 >= $minNights2) ? $dpMv : 100;
    // Nếu user chọn full → 100%, ngược lại theo config
    $dpPctv = ($paymentOption === 'full') ? 100 : $dpPctAuto;
    $dpAmt = (int)round($totalAmount * $dpPctv / 100);
    $remaining = $totalAmount - $dpAmt;
    $showChoice=($minNights2> 0 && $n2 >= $minNights2 &&
        $dpPctAuto < 100); @endphp {{-- Chọn hình thức thanh toán — hiện khi>= 2 đêm + có cọc --}}
            @if($showChoice)
            <div class="mb-3">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Hình thức thanh toán</p>
                <div class="grid grid-cols-2 gap-2" x-data="{ pending: null }"
                    x-init="$wire.$watch('paymentOption', () => { pending = null })">

                    {{-- Nút Cọc --}}
                    <button type="button"
                                                    wire:click="selectPaymentOption('deposit')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="selectPaymentOption"
                                                    @click="pending = 'deposit'"
                                                    :disabled="pending !== null"
                                                    class="relative flex flex-col items-center justify-center gap-1 p-3 rounded-xl border-2 transition-all overflow-hidden
                                                        {{ $paymentOption === 'deposit' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 bg-white hover:border-amber-200' }}"
                                                    :class="pending === 'deposit' ? '!border-amber-400 !bg-amber-50' : ''">

                                                    {{-- Spinner overlay khi đang chờ Livewire --}}
                                                    <div x-show="pending === 'deposit'"
                                                         x-cloak
                                                         class="absolute inset-0 flex items-center justify-center rounded-xl bg-amber-50/90 z-10">
                                                        <svg class="animate-spin w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                                        </svg>
                                                    </div>

                                                    <iconify-icon icon="lucide:coins" width="22"
                                                        class="{{ $paymentOption === 'deposit' ? 'text-amber-500' : 'text-gray-400' }}"
                                                        :class="pending === 'deposit' ? '!text-amber-500' : ''"></iconify-icon>
                                                    <span class="text-sm font-bold {{ $paymentOption === 'deposit' ? 'text-amber-700' : 'text-gray-600' }}"
                                                          :class="pending === 'deposit' ? '!text-amber-700' : ''">
                                                        Cọc {{ $dpPctAuto }}%
                                                    </span>
                                                    <span class="text-xs {{ $paymentOption === 'deposit' ? 'text-amber-600' : 'text-gray-400' }}"
                                                          :class="pending === 'deposit' ? '!text-amber-600' : ''">
                                                        {{ number_format((int)round($totalAmount * $dpPctAuto / 100), 0, ',', '.') }}đ trước
                                                    </span>
                                                </button>

                    {{-- Nút Thanh toán 100% --}}
                    <button type="button"
                                                    wire:click="selectPaymentOption('full')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="selectPaymentOption"
                                                    @click="pending = 'full'"
                                                    :disabled="pending !== null"
                                                    class="relative flex flex-col items-center justify-center gap-1 p-3 rounded-xl border-2 transition-all overflow-hidden
                                                        {{ $paymentOption === 'full' ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-green-200' }}"
                                                    :class="pending === 'full' ? '!border-green-500 !bg-green-50' : ''">

                                                    {{-- Spinner overlay khi đang chờ Livewire --}}
                                                    <div x-show="pending === 'full'"
                                                         x-cloak
                                                         class="absolute inset-0 flex items-center justify-center rounded-xl bg-green-50/90 z-10">
                                                        <svg class="animate-spin w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                                        </svg>
                                                    </div>

                                                    <iconify-icon icon="lucide:badge-check" width="22"
                                                        class="{{ $paymentOption === 'full' ? 'text-green-500' : 'text-gray-400' }}"
                                                        :class="pending === 'full' ? '!text-green-500' : ''"></iconify-icon>
                                                    <span class="text-sm font-bold {{ $paymentOption === 'full' ? 'text-green-700' : 'text-gray-600' }}"
                                                          :class="pending === 'full' ? '!text-green-700' : ''">
                                                        Thanh toán 100%
                                                    </span>
                                                    <span class="text-xs {{ $paymentOption === 'full' ? 'text-green-600' : 'text-gray-400' }}"
                                                          :class="pending === 'full' ? '!text-green-600' : ''">
                                                        {{ number_format($totalAmount, 0, ',', '.') }}đ
                                                    </span>
                                                </button>

                </div>
            </div>
            @endif

            @php
            $rcfg2 = $product->room_config ?? [];
            $mfg2 = (int)($rcfg2['max_free_guests'] ?? 2);
            $egc2 = max(0, (int)$guests - $mfg2);
            @endphp
            @if(isset($extraFee) && $extraFee > 0)
            <div class="flex items-center justify-between mb-1">
                <span class="text-sm text-gray-500">Tiền phòng:</span>
                <span class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount - $extraFee, 0, ',', '.') }}đ</span>
            </div>
            <div class="flex items-center justify-between mb-1">
                <span class="text-sm text-gray-500">Phụ thu {{ $egc2 }} khách thêm:</span>
                <span class="text-sm font-semibold" style="color:#b45309">+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
            </div>
            <div class="flex items-center justify-between mb-2 pb-2 border-b border-gray-300">
                <span class="text-sm font-bold text-gray-700">Tổng tiền phòng:</span>
                <span class="text-sm font-bold text-gray-800">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
            </div>
            @else
            <div class="flex items-center justify-between mb-1">
                <span class="text-sm text-gray-500">Tổng tiền phòng:</span>
                <span class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
            </div>
            @endif
            @if($dpPctv < 100) <div class="rounded-lg px-3 py-2 mb-1"
                style="background:#fffbeb; border:1px solid #fbbf24">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-amber-700">Cần thanh toán cọc ({{ $dpPctv }}%):</span>
                    <span class="text-lg font-extrabold text-amber-700">{{ number_format($dpAmt, 0, ',', '.') }}đ</span>
                </div>
                <p class="text-xs text-amber-600 mt-1 flex items-center gap-1">
                    <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="16" x2="12" y2="12" />
                        <line x1="12" y1="8" x2="12" y2="8.01" />
                    </svg>
                    Còn lại {{ number_format($remaining, 0, ',', '.') }}đ thanh toán khi nhận phòng
                </p>
</div>
<p class="text-[11px] text-gray-400 italic text-center">
    Mã cổng được gửi sau khi thanh toán đủ
</p>
@else
<div class="flex items-center justify-between">
    <span class="text-sm font-bold text-gray-700">Tổng thanh toán:</span>
    <span class="text-xl font-extrabold" style="color:#4e6b4c">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
</div>
<p class="text-[11px] text-gray-400 italic text-center mt-1">
    Mã cổng được gửi ngay sau khi thanh toán đủ
</p>
@endif
@else
@php
$rcfg3 = $product->room_config ?? [];
$mfg3 = (int)($rcfg3['max_free_guests'] ?? 2);
$egc3 = max(0, (int)$guests - $mfg3);
@endphp
@if(isset($extraFee) && $extraFee > 0)
<div class="flex items-center justify-between mb-1">
    <span class="text-sm text-gray-500">Tiền phòng:</span>
    <span class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount - $extraFee, 0, ',', '.') }}đ</span>
</div>
<div class="flex items-center justify-between mb-2">
    <span class="text-sm text-gray-500">Phụ thu {{ $egc3 }} khách thêm:</span>
    <span class="text-sm font-semibold" style="color:#b45309">+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
</div>
@endif
<div
    class="flex items-center justify-between {{ isset($extraFee) && $extraFee > 0 ? 'pt-1 border-t border-gray-300' : '' }}">
    <span class="text-sm font-bold text-gray-700">Tổng thanh toán:</span>
    <span class="text-xl font-extrabold" style="color:#4e6b4c">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
</div>
@endif
</div>
@endif

{{-- Họ và tên --}}
@if($isAuthUser)
<div class="w-full border border-green-300 bg-green-50 rounded-md p-[10px] flex items-center gap-2.5">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
    </svg>
    <span class="text-gray-800 text-sm font-medium flex-1">{{ $buyerName }}</span>
    <span class="text-xs font-medium text-green-700 flex items-center gap-1">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
        </svg>
        Đã xác thực
    </span>
</div>
@else
<input
    type="text"
    id="buyerName"
    wire:model="buyerName"
    placeholder="Họ và tên"
    class="w-full border rounded-md p-[10px] focus:ring-2 focus:ring-[#4e6b4c] focus:border-[#4e6b4c] focus:outline-none {{ $errors->has('buyerName') ? 'border-2 border-red-600' : 'border-black' }}"
/>
@endif

{{-- Số điện thoại --}}
<div>
    @if($isAuthUser)
    <div class="w-full border border-green-300 bg-green-50 rounded-md p-[10px] flex items-center gap-2.5">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
        </svg>
        <span class="text-gray-800 text-sm font-medium flex-1">{{ $buyerPhone }}</span>
        <span class="text-xs font-medium text-green-700 flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
            </svg>
            Đã xác thực
        </span>
    </div>
    @else
    <input
        type="text"
        id="buyerPhone"
        wire:model="buyerPhone"
        placeholder="Số điện thoại"
        class="w-full border rounded-md p-[10px] focus:ring-2 focus:ring-[#4e6b4c] focus:border-[#4e6b4c] focus:outline-none {{ $errors->has('buyerPhone') ? 'border-2 border-red-600' : 'border-black' }}"
    />
    @endif
    <p class="text-sm text-gray-500 mt-1 italic">
        @if($isAuthUser)
        * Thông tin được lấy từ tài khoản đã đăng nhập của bạn.
        @else
        * Bạn vui lòng nhập đúng số điện thoại, Home sẽ gửi thông tin check-in qua Zalo ạ
        @endif
    </p>
</div>

@php
$cfg = $product->room_config ?? [];
$maxFreeG = (int)($cfg['max_free_guests'] ?? 2);
$feeEachG = (int)($cfg['extra_guest_fee'] ?? 50000);
$initGuests = (int)$guests;
$initIsCustom = $initGuests > 4;
$initCustomVal = $initIsCustom ? $initGuests : 5;
$initSelectVal = $initIsCustom ? 'custom' : (string)$initGuests;
@endphp
<div wire:ignore x-data="{
                                    isCustom: {{ $initIsCustom ? 'true' : 'false' }},
                                    selectVal: '{{ $initSelectVal }}',
                                    customVal: {{ $initCustomVal }},
                                    onSelectChange() {
                                        if (this.selectVal === 'custom') {
                                            this.isCustom = true;
                                            $wire.set('guests', this.customVal);
                                        } else {
                                            this.isCustom = false;
                                            $wire.set('guests', parseInt(this.selectVal));
                                        }
                                    },
                                    onCustomInput() {
                                        let n = parseInt(this.customVal);
                                        if (isNaN(n) || n < 2) return;
                                        $wire.set('guests', n);
                                    }
                                }">
    <label for="guests" class="block font-medium text-lg mb-1">Số lượng khách</label>
    <select id="guests"
                                            x-model="selectVal"
                                            @change="onSelectChange()"
                                            class="w-full px-4 py-2 border border-black rounded-lg focus:ring-2 focus:ring-primary focus:border-primary focus:outline-none">
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                        <option value="4">4</option>
                                        <option value="custom">Tùy chỉnh...</option>
                                    </select>
    <div x-show="isCustom" x-cloak class="mt-2">
        <input
                                            type="number"
                                            x-model.number="customVal"
                                            @input.debounce.600ms="onCustomInput()"
                                            min="2"
                                            placeholder="Nhập số lượng khách"
                                            class="w-full border border-black rounded-md p-[10px] focus:ring-2 focus:ring-[#4e6b4c] focus:border-[#4e6b4c] focus:outline-none"
                                        />
    </div>
    {{-- Ghi chú phụ thu --}}
    <div class="mt-1">
        <p class="text-sm italic text-gray-500">
            * Nếu > {{ $maxFreeG }} khách, phụ thu {{ number_format($feeEachG, 0, ',', '.') }}đ/người thêm, tính từ
            người thứ {{ $maxFreeG + 1 }} về sau.
        </p>
    </div>
    @if($bookingStyle != 2)
    <p class="text-sm italic text-gray-500 mt-1">* Home chỉ nhận tối đa 2 khách nếu
        khách book có khung giờ qua đêm.</p>
    @endif
</div>
<input type="hidden" wire:model="startTime" id="startTimeInput">
<input type="hidden" wire:model="endTime" id="endTimeInput">

<!-- MÃ GIẢM GIÁ -->
<div class="bg-gray-50 rounded-lg p-4">
    <h3 class="text-xl font-semibold mb-3 flex items-center gap-2">
        <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
        </svg>
        Mã giảm giá
    </h3>

    @if($appliedCoupon)
    {{-- Hiển thị khi đã áp dụng coupon --}}
    <div class="bg-green-50 border-2 border-green-300 rounded-lg p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-block bg-green-500 text-white px-3 py-1 rounded-full text-sm font-bold">
                                                            {{ $appliedCoupon->code }}
                                                        </span>
                    <span class="text-green-700 font-medium">
                                                            {{ $appliedCoupon->name }}
                                                        </span>
                </div>
                <p class="text-sm text-gray-700">
                    @if($appliedCoupon->type === 'percentage')
                    Giảm {{ $appliedCoupon->value }}%
                    @if($appliedCoupon->max_discount)
                    (tối đa {{ number_format($appliedCoupon->max_discount, 0, ',', '.') }}đ)
                    @endif
                    @else
                    Giảm {{ number_format($appliedCoupon->value, 0, ',', '.') }}đ
                    @endif
                </p>
                <p class="text-sm font-bold text-green-600 mt-1">
                    Bạn được giảm: {{ number_format($couponDiscountAmount, 0, ',', '.') }}đ
                </p>
            </div>
            <button
                                                        type="button"
                                                        wire:click="removeCoupon"
                                                        class="text-red-500 hover:text-red-700 transition p-1"
                                                        title="Xóa mã giảm giá"
                                                >
                                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                </button>
        </div>
    </div>
    @else
    {{-- Form nhập mã coupon --}}
    <div class="space-y-3">
        <div class="flex gap-2">
            <input
                                                        type="text"
                                                        wire:model.defer="couponCode"
                                                        placeholder="Nhập mã giảm giá (VD: SUMMER2024)"
                                                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent uppercase"
                                                        style="text-transform: uppercase;"
                                                    @if(!$canApplyCoupon) disabled @endif
                                                >
            <button
                                                        type="button"
                                                        wire:click="applyCoupon"
                                                        class="px-6 py-2 bg-primary text-white rounded-lg font-medium hover:bg-opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                                                    @if(!$canApplyCoupon) disabled @endif
                                                >
                                                    Áp dụng
                                                </button>
        </div>

        {{-- Thông báo lỗi --}}
        @if($couponErrorMessage)
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-sm flex items-start gap-2">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                    clip-rule="evenodd" />
            </svg>
            <span>{{ $couponErrorMessage }}</span>
        </div>
        @endif

        {{-- Helper text --}}
        @if($canApplyCoupon)
        <p class="text-sm text-gray-500">
            Có mã giảm giá? Nhập và áp dụng để được ưu đãi!
        </p>
        @elseif($bookingStyle == 2)
        <p class="text-sm text-gray-500 italic">
            * Vui lòng chọn ngày nhận và trả phòng trước khi áp dụng mã giảm giá
        </p>
        @else
        <p class="text-sm text-gray-500 italic">
            * Vui lòng chọn khung giờ trước khi áp dụng mã giảm giá
        </p>
        @endif
    </div>
    @endif
</div>

<!-- CCCD -->
<div>
    <h3 class="text-xl font-semibold mb-2">Căn cước công dân</h3>

    @if($isAuthUser && !empty($authCccdFront) && !empty($authCccdBack))
    {{-- Auth user đã có CCCD trong profile → hiển thị ảnh đã lưu --}}
    <div class="bg-green-50 border border-green-300 rounded-xl p-3 mb-3 flex items-start gap-2">
        <svg class="w-4 h-4 text-green-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <div class="flex-1">
            <p class="text-sm font-semibold text-green-800">CCCD đã xác minh từ hồ sơ của bạn</p>
            <p class="text-xs text-green-600 mt-0.5">Thông tin CCCD được lấy tự động — không cần upload lại.</p>
        </div>
    </div>
    <div class="flex gap-3">
        @foreach([['front', $authCccdFront, 'Mặt trước'], ['back', $authCccdBack, 'Mặt sau']] as [$side, $path, $label])
        @php $url = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : ''; @endphp
        <div class="relative group flex-1 h-36 border-2 border-green-300 rounded-xl overflow-hidden bg-green-50">
            <img src="{{ $url }}" alt="{{ $label }} CCCD"
                 class="absolute inset-0 w-full h-full object-cover rounded-xl">
            {{-- Overlay cho phép upload lại --}}
            <label class="absolute inset-0 flex flex-col items-center justify-center bg-black/0 group-hover:bg-black/40 transition-all cursor-pointer rounded-xl">
                <input type="file" accept="image/*" class="hidden"
                       onchange="processAndUpload(this, 'cccd_{{ $side }}')" />
                <div class="opacity-0 group-hover:opacity-100 transition-opacity text-center">
                    <svg class="w-6 h-6 text-white mx-auto mb-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                    </svg>
                    <span class="text-white text-xs font-semibold">Đổi ảnh</span>
                </div>
            </label>
            {{-- Badge xác nhận --}}
            <div class="absolute top-2 left-2 bg-green-500 rounded-full px-2 py-0.5 flex items-center gap-1 shadow">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                <span class="text-white text-[10px] font-bold">{{ $label }}</span>
            </div>
            {{-- JS-managed loading overlay (cho upload mới) --}}
            <div wire:ignore>
                <div id="loading-cccd_{{ $side }}" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20 rounded-xl flex flex-col items-center justify-center">
                    <div class="animate-spin rounded-full h-6 w-6 border-2 border-black border-t-primary mb-2"></div>
                    <p class="text-xs font-medium" id="status-cccd_{{ $side }}">Đang xử lý...</p>
                    <div class="w-20 h-1 bg-gray-200 rounded-full overflow-hidden mt-1">
                        <div id="progress-cccd_{{ $side }}" class="h-full bg-primary rounded-full transition-all" style="width:0%"></div>
                    </div>
                </div>
                <img id="preview-cccd_{{ $side }}" src="" class="hidden absolute inset-0 w-full h-full object-cover rounded-xl" alt="{{ $label }} CCCD mới"/>
                <div id="checkmark-cccd_{{ $side }}" class="hidden absolute top-2 right-2 bg-green-500 rounded-full p-1 shadow z-10">
                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @else
    {{-- Chưa có CCCD → hiển thị upload zone thông thường --}}
    <div class="flex gap-4">
        @foreach([['cccd_front', 'Mặt trước'], ['cccd_back', 'Mặt sau']] as [$field, $label])
        <label class="relative group flex-1 h-40 border-4 border-dashed rounded-xl cursor-pointer overflow-hidden transition-all duration-300 hover:border-primary hover:bg-blue-50/30
                {{ $errors->has($field) ? 'border-red-600' : 'border-black' }}">
            <input type="file" accept="image/*" class="hidden"
                   onchange="processAndUpload(this, '{{ $field }}')" />
            <div wire:ignore class="absolute inset-0">
                <div id="loading-{{ $field }}" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20 rounded-xl">
                    <div class="flex flex-col items-center justify-center h-full">
                        <div class="animate-spin rounded-full h-8 w-8 border-3 border-black border-t-primary mb-3"></div>
                        <p class="text-xs font-medium text-black mb-2" id="status-{{ $field }}">Đang xử lý...</p>
                        <div class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                            <div id="progress-{{ $field }}" class="h-full bg-primary rounded-full transition-all duration-300" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
                <img id="preview-{{ $field }}" src="" class="hidden absolute inset-0 w-full h-full object-cover rounded-xl" alt="{{ $label }} CCCD"/>
                <div class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-300 rounded-xl flex items-center justify-center">
                    <div class="bg-white/90 rounded-lg px-3 py-1 text-sm font-medium text-black">Thay đổi ảnh</div>
                </div>
                <div id="checkmark-{{ $field }}" class="hidden absolute top-3 right-3 bg-green-500 rounded-full p-1.5 shadow-lg z-10">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                </div>
                <div id="placeholder-{{ $field }}" class="flex flex-col items-center justify-center h-full p-4 text-center">
                    <svg xmlns='http://www.w3.org/2000/svg' class="w-12 h-12 mx-auto text-black group-hover:text-primary transition-colors duration-300" stroke="currentColor" fill="none" viewBox="0 0 48 48"><path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    <div class="mt-3">
                        <p class="text-lg font-semibold text-black group-hover:text-primary">{{ $label }}</p>
                        <p class="text-sm text-black mt-1 group-hover:text-primary opacity-60">Chọn ảnh hoặc chụp ảnh</p>
                    </div>
                </div>
            </div>
        </label>
        @endforeach
    </div>
    @endif

    <p class="text-sm text-gray-500 mt-2 text-justify">* Thông tin CCCD được lưu trữ và bảo mật để khai báo lưu trú, xóa sau khi check-out.</p>
</div>

<textarea id="note" wire:model="note" placeholder="Ghi chú cho 365Home" rows="3"
                                          class="w-full px-4 py-3 border border-black rounded-lg focus:ring-2 focus:ring-primary focus:border-primary focus:outline-none resize-none text-base"></textarea>

<label class="flex items-start gap-2 text-base ">
                                    <input type="checkbox" wire:model="accept1"
                                           class="mt-1 rounded-sm text-primary focus:ring-primary
                                           {{ $errors->has('accept1') ? 'border border-red-600' : 'border-black' }}"/>
                                    <span class="text-red-700 text-lg">Xác nhận mọi người đã đủ tuổi vị thành niên, hoặc trẻ em phải có người giám hộ. Người Đặt phòng chịu trách nhiệm với thông tin này.</span>
                                </label>

<label class="flex items-start gap-2 text-base text-black">
                                    <input type="checkbox" wire:model="accept2"
                                           class="mt-1 rounded-sm border text-primary focus:ring-primary
                                            {{ $errors->has('accept2') ? 'border border-red-600' : 'border-black' }}"/>
                                    <span class="text-lg">Sau khi quét mã thanh toán thành công bạn hãy quay lại đây để chụp thông tin Booking (<strong>Tick để tiếp tục Đặt phòng</strong>)</span>
                                </label>

<div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
    <p class="text-[14px] font-bold text-amber-800 mb-2">Điều kiện được hoàn tiền</p>
    <p class="text-[13px] text-amber-700 mb-1">Nếu bạn đã thanh toán, số tiền được hoàn lại sẽ bằng <strong>90%</strong>
        số tiền bạn đã thanh toán tại thời điểm hủy và được áp dụng theo <a href=""
            class="underline hover:text-amber-900">chính sách hoàn tiền</a> của chúng tôi.</p>
    <p class="text-[13px] text-amber-600 mb-3 italic">10% còn lại được tính là phí xử lý giao dịch và chi phí vận hành
        đã phát sinh.</p>
    <label class="flex items-start gap-2 cursor-pointer select-none">
                                        <input type="checkbox" wire:model="acceptRefundPolicy"
                                               class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary cursor-pointer shrink-0
                                               {{ $errors->has('acceptRefundPolicy') ? 'border-2 border-red-600' : 'border-gray-300' }}"/>
                                        <span class="text-[13px] text-gray-700 leading-snug">Tôi đã đọc và đồng ý với <a href="" class="font-semibold text-amber-800 underline hover:text-amber-900">chính sách hoàn tiền</a> của 365Home.</span>
                                    </label>
    @error('acceptRefundPolicy')
    <p class="text-red-600 text-[13px] mt-1 font-medium">{{ $message }}</p>
    @enderror
</div>

<div class="text-[16px] px-[12px] my-6">
    Khi bấm 'Đặt phòng' đồng nghĩa với việc bạn đã đọc và đồng ý với các <a href="" class="text-primary">Nội quy</a> &
    <a href="" class="text-primary">Chính sách</a> của 365HOME
</div>

<div class="form-text" style="background: #ebebeb; padding: 7px 7px;">
    <em>*Chú ý:</em>
    <br>- Bạn đang đặt phòng tại: <strong>365Home - {{ $categories['c3'] }}.</strong>
    <br>- Đây là hệ thống đặt phòng tự động, nên khi bấm 'Đặt phòng' bạn sẽ được chuyển sang quét mã QR để thanh toán qua App ngân hàng, thời gian giữ phòng là 5 phút chờ thanh toán.
</div>

<button type="submit"
                                        class="w-full bg-primary text-white font-semibold py-4 rounded-lg hover:bg-gray-500 transition text-lg">
                                    Đặt phòng
                                </button>
</form>

@include('bladethemev1::components.payments.booking-confirmation-modal')
</div>