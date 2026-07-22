{{-- Style 1: Tóm tắt khung giờ đã chọn từ bảng bên trái --}}
@if ($bookingStyle == 1)
    @if (!empty($selectedSlots))
        @php
            // Lấy mốc bắt đầu/kết thúc THỰC của từng slot (cộng thêm 1 ngày nếu là khung giờ
            // qua đêm), rồi lấy min(start) → max(end) trên toàn bộ slot đã chọn — không chỉ dựa
            // vào slot có 'date' lớn nhất, vì slot đó chưa chắc có endTime muộn nhất.
            $slotRanges = collect($selectedSlots)->map(function ($slot) {
                $start = \Carbon\Carbon::parse($slot['date'] . ' ' . $slot['startTime']);
                $end = \Carbon\Carbon::parse($slot['date'] . ' ' . $slot['endTime']);
                if (!empty($slot['overNight']) || $end->lt($start)) {
                    $end->addDay();
                }
                return ['start' => $start, 'end' => $end];
            });
            $checkinDt = $slotRanges->pluck('start')->min();
            $checkoutDt = $slotRanges->pluck('end')->max();
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
                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Nhận phòng
                    </p>
                    <p class="text-xl font-extrabold text-gray-900">{{ $checkinDt->format('H:i') }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $checkinDt->format('d/m/Y') }}</p>
                </div>
                <div class="bg-white rounded-lg p-3 text-center">
                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Trả phòng
                    </p>
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
            <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor"
                stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <p class="text-sm text-gray-400">Vui lòng chọn khung giờ<br>từ bảng bên trái</p>
        </div>
    @endif

    {{-- Style 2: Tóm tắt ngày nhận/trả phòng (chỉ hiển thị ngày, không có giờ) --}}
@elseif($bookingStyle == 2)
    @if (!empty($startTime) && !empty($endTime))
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
                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Nhận phòng
                    </p>
                    <p class="text-xl font-extrabold text-gray-900">{{ $style2CheckinTimeDisplay }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $checkinDt2->format('d/m/Y') }}</p>
                </div>
                <div class="bg-white rounded-lg p-3 text-center">
                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Trả phòng
                    </p>
                    <p class="text-xl font-extrabold text-gray-900">{{ $style2CheckoutTimeDisplay }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $checkoutDt2->format('d/m/Y') }}</p>
                </div>
            </div>
            <p class="text-xs text-center mt-2 font-semibold" style="color:#4e6b4c">
                {{ $nights }} đêm
            </p>
            @php
                $dpM = (int) ($product->deposit_multi_night ?? 50);
                $minNights = (int) ($product->deposit_min_nights ?? 2);
                // Có cọc khi: minNights > 0 VÀ số đêm >= ngưỡng
                $dpPctBase = $minNights > 0 && $nights >= $minNights ? $dpM : 100;
                $dpPct = $paymentOption === 'full' ? 100 : $dpPctBase;
            @endphp
            @if ($dpPct < 100)
                <div
                    class="mt-2 flex items-center justify-center gap-1.5 bg-amber-50 border border-amber-300 rounded-lg px-3 py-1.5">
                    <svg class="w-3.5 h-3.5 text-amber-600 shrink-0" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                    <span class="text-xs text-amber-700 font-semibold">Cọc {{ $dpPct }}% để xác nhận đặt
                        phòng</span>
                </div>
            @else
                <div
                    class="mt-2 flex items-center justify-center gap-1.5 bg-green-50 border border-green-300 rounded-lg px-3 py-1.5">
                    <svg class="w-3.5 h-3.5 text-green-600 shrink-0" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="text-xs text-green-700 font-semibold">Thanh toán 100% — nhận mã cổng
                        ngay</span>
                </div>
            @endif
        </div>
    @endif
@endif

@php
    $canApplyCoupon = $bookingStyle == 2 ? !empty($startTime) && !empty($endTime) : !empty($selectedSlots);

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
@if ($bookingServiceTotal > 0 && $additionalServices)
    <div class="space-y-1">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Dịch vụ thêm</p>
        @foreach ($selectedServices as $svcId => $svcQty)
            @php $svc = $additionalServices->firstWhere('id', $svcId); @endphp
            @if ($svc && $svcQty > 0)
                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                    <div class="flex items-center gap-2">
                        @if (!empty($svc->image))
                            <img src="{{ asset('storage/' . $svc->image) }}" alt="{{ $svc->name }}"
                                class="w-7 h-7 object-contain rounded">
                        @else
                            <span class="text-base">🍽️</span>
                        @endif
                        <span class="text-xs font-medium text-gray-700">{{ $svc->name }}</span>
                        <span class="text-xs text-gray-400">x{{ $svcQty }}</span>
                    </div>
                    <span class="text-xs font-semibold"
                        style="color:#4e6b4c">+{{ number_format($svc->price * $svcQty, 0, ',', '.') }}đ</span>
                </div>
            @endif
        @endforeach
    </div>
@endif

{{-- Tóm tắt dịch vụ & tổng tiền --}}
@if ($totalAmount > 0 || $bookingServiceTotal > 0)
    <div class="rounded-xl p-4 relative" style="background:#f0f4f0; border:2px solid #4e6b4c">
        {{-- Loading overlay khi Livewire đang tính lại --}}
        <div wire:loading wire:target="guests"
            class="absolute inset-0 rounded-xl bg-white/70 flex items-center justify-center z-10 backdrop-blur-[1px]">
            <div class="flex items-center gap-2 bg-white rounded-full px-4 py-2 shadow-sm">
                <svg class="animate-spin w-4 h-4" style="color:#4e6b4c" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4" />
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
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
        @if ($bookingStyle == 2 && !empty($startTime) && !empty($endTime))
            @php
                $ci2 = \Carbon\Carbon::parse($startTime)->startOfDay();
                $co2 = \Carbon\Carbon::parse($endTime)->startOfDay();
                $n2 = $ci2->diffInDays($co2);
                $dpMv = (int) ($product->deposit_multi_night ?? 50);
                $minNights2 = (int) ($product->deposit_min_nights ?? 2);
                // Có cọc khi: minNights > 0 VÀ số đêm >= ngưỡng
                $dpPctAuto = $minNights2 > 0 && $n2 >= $minNights2 ? $dpMv : 100;
                // Nếu user chọn full → 100%, ngược lại theo config
                $dpPctv = $paymentOption === 'full' ? 100 : $dpPctAuto;
                $dpAmt = (int) round(($totalAmount * $dpPctv) / 100);
                $remaining = $totalAmount - $dpAmt;
            $showChoice = $minNights2 > 0 && $n2 >= $minNights2 && $dpPctAuto < 100; @endphp {{-- Chọn hình thức thanh toán — hiện khi>= 2 đêm + có cọc --}}
            @if ($showChoice)
                <div class="mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Hình thức thanh
                        toán</p>
                    <div class="grid grid-cols-2 gap-2" x-data="{ pending: null }" x-init="$wire.$watch('paymentOption', () => { pending = null })">

                        {{-- Nút Cọc --}}
                        <button type="button" wire:click="selectPaymentOption('deposit')"
                            wire:loading.attr="disabled" wire:target="selectPaymentOption"
                            @click="pending = 'deposit'" :disabled="pending !== null"
                            class="relative flex flex-col items-center justify-center gap-1 p-3 rounded-xl border-2 transition-all overflow-hidden
                                                {{ $paymentOption === 'deposit' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 bg-white hover:border-amber-200' }}"
                            :class="pending === 'deposit' ? '!border-amber-400 !bg-amber-50' : ''">

                            {{-- Spinner overlay khi đang chờ Livewire --}}
                            <div x-show="pending === 'deposit'" x-cloak
                                class="absolute inset-0 flex items-center justify-center rounded-xl bg-amber-50/90 z-10">
                                <svg class="animate-spin w-5 h-5 text-amber-500" fill="none"
                                    viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            </div>

                            <iconify-icon icon="lucide:coins" width="22"
                                class="{{ $paymentOption === 'deposit' ? 'text-amber-500' : 'text-gray-400' }}"
                                :class="pending === 'deposit' ? '!text-amber-500' : ''"></iconify-icon>
                            <span
                                class="text-sm font-bold {{ $paymentOption === 'deposit' ? 'text-amber-700' : 'text-gray-600' }}"
                                :class="pending === 'deposit' ? '!text-amber-700' : ''">
                                Cọc {{ $dpPctAuto }}%
                            </span>
                            <span
                                class="text-xs {{ $paymentOption === 'deposit' ? 'text-amber-600' : 'text-gray-400' }}"
                                :class="pending === 'deposit' ? '!text-amber-600' : ''">
                                {{ number_format((int) round(($totalAmount * $dpPctAuto) / 100), 0, ',', '.') }}đ
                                trước
                            </span>
                        </button>

                        {{-- Nút Thanh toán 100% --}}
                        <button type="button" wire:click="selectPaymentOption('full')"
                            wire:loading.attr="disabled" wire:target="selectPaymentOption"
                            @click="pending = 'full'" :disabled="pending !== null"
                            class="relative flex flex-col items-center justify-center gap-1 p-3 rounded-xl border-2 transition-all overflow-hidden
                                                {{ $paymentOption === 'full' ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-green-200' }}"
                            :class="pending === 'full' ? '!border-green-500 !bg-green-50' : ''">

                            {{-- Spinner overlay khi đang chờ Livewire --}}
                            <div x-show="pending === 'full'" x-cloak
                                class="absolute inset-0 flex items-center justify-center rounded-xl bg-green-50/90 z-10">
                                <svg class="animate-spin w-5 h-5 text-green-500" fill="none"
                                    viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4" />
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                </svg>
                            </div>

                            <iconify-icon icon="lucide:badge-check" width="22"
                                class="{{ $paymentOption === 'full' ? 'text-green-500' : 'text-gray-400' }}"
                                :class="pending === 'full' ? '!text-green-500' : ''"></iconify-icon>
                            <span
                                class="text-sm font-bold {{ $paymentOption === 'full' ? 'text-green-700' : 'text-gray-600' }}"
                                :class="pending === 'full' ? '!text-green-700' : ''">
                                Thanh toán 100%
                            </span>
                            <span
                                class="text-xs {{ $paymentOption === 'full' ? 'text-green-600' : 'text-gray-400' }}"
                                :class="pending === 'full' ? '!text-green-600' : ''">
                                {{ number_format($totalAmount, 0, ',', '.') }}đ
                            </span>
                        </button>

                    </div>
                </div>
            @endif

            @php
                $rcfg2 = $product->room_config ?? [];
                $mfg2 = (int) ($rcfg2['max_free_guests'] ?? 2);
                $egc2 = max(0, (int) $guests - $mfg2);
            @endphp
            @if (isset($extraFee) && $extraFee > 0)
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm text-gray-500">Tiền phòng:</span>
                    <span
                        class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount - $extraFee, 0, ',', '.') }}đ</span>
                </div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm text-gray-500">Phụ thu {{ $egc2 }} khách thêm:</span>
                    <span class="text-sm font-semibold"
                        style="color:#b45309">+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
                </div>
                <div class="flex items-center justify-between mb-2 pb-2 border-b border-gray-300">
                    <span class="text-sm font-bold text-gray-700">Tổng tiền phòng:</span>
                    <span
                        class="text-sm font-bold text-gray-800">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                </div>
            @else
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm text-gray-500">Tổng tiền phòng:</span>
                    <span
                        class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                </div>
            @endif
            @if ($dpPctv < 100)
                <div class="rounded-lg px-3 py-2 mb-1" style="background:#fffbeb; border:1px solid #fbbf24">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-bold text-amber-700">Cần thanh toán cọc
                            ({{ $dpPctv }}%):</span>
                        <span
                            class="text-lg font-extrabold text-amber-700">{{ number_format($dpAmt, 0, ',', '.') }}đ</span>
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
                    <span class="text-xl font-extrabold"
                        style="color:#4e6b4c">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                </div>
                <p class="text-[11px] text-gray-400 italic text-center mt-1">
                    Mã cổng được gửi ngay sau khi thanh toán đủ
                </p>
            @endif
        @else
            @php
                $rcfg3 = $product->room_config ?? [];
                $mfg3 = (int) ($rcfg3['max_free_guests'] ?? 2);
                $egc3 = max(0, (int) $guests - $mfg3);
            @endphp
            @if (isset($extraFee) && $extraFee > 0)
                <div class="flex items-center justify-between mb-1">
                    <span class="text-sm text-gray-500">Tiền phòng:</span>
                    <span
                        class="text-sm font-semibold text-gray-700">{{ number_format($totalAmount - $extraFee, 0, ',', '.') }}đ</span>
                </div>
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-500">Phụ thu {{ $egc3 }} khách thêm:</span>
                    <span class="text-sm font-semibold"
                        style="color:#b45309">+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
                </div>
            @endif
            <div
                class="flex items-center justify-between {{ isset($extraFee) && $extraFee > 0 ? 'pt-1 border-t border-gray-300' : '' }}">
                <span class="text-sm font-bold text-gray-700">Tổng thanh toán:</span>
                <span class="text-xl font-extrabold"
                    style="color:#4e6b4c">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
            </div>
        @endif
    </div>
@endif
