<div
    class="bg-white p-0 rounded-2xl lg:rounded-2xl border border-[#DDDDDD] shadow-[0_2px_16px_rgba(0,0,0,0.08)] overflow-hidden"
    id="pd-book-card">
    {{-- Drag handle (mobile bottom-sheet only) --}}
    <div class="lg:hidden" id="pd-book-sheet-handle"></div>

    {{-- Badge "Nhập thông tin đặt phòng" — CHỈ hiện khi sheet đang ở trạng thái thu gọn mặc định
         (không có class pd-sheet-peek/pd-sheet-full, xem CSS #pd-book-badge phía dưới). Bấm vào
         mở thẳng sheet lên "full" (90% màn hình) để khách nhập thông tin ngay, không dừng ở mức
         "peek" trung gian nữa. --}}
    <button type="button" class="lg:hidden" id="pd-book-badge" aria-label="Nhập thông tin đặt phòng">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
        </svg>
        <span id="pd-book-badge-text">Nhập thông tin đặt phòng</span>
        {{-- Chevron nảy lên (bounce) — gợi ý trực quan "vuốt/bấm lên để mở", tránh badge trông
             giống chữ thường không rõ là bấm được. --}}
        <svg id="pd-book-badge-chevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
        </svg>
    </button>

    <div id="pd-book-sheet-scroll">
    <div class="px-6 pt-6 pb-2 flex items-center justify-between gap-3">
        <p class="text-xl font-semibold text-[#222222]">Thông tin Đặt phòng</p>
        {{-- Nút tắt bottom sheet (mobile) — cho phép thu gọn sheet lại mà không cần vuốt tay --}}
        <button type="button" class="lg:hidden shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-[#F7F7F7] text-[#717171] hover:bg-[#EEEEEE] hover:text-[#222222] transition-colors"
            id="pd-book-sheet-close" aria-label="Đóng">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    {{-- Alpine.js: tự động prefill từ localStorage khi đã đăng nhập --}}
    <div x-data="{}" x-init="const token = localStorage.getItem('auth_token');
    if (token) $wire.prefillFromAuth(token);
    window.addEventListener('auth-state-changed', () => {
        const t = localStorage.getItem('auth_token');
        $wire.prefillFromAuth(t || '');
    });"></div>

    <form wire:submit.prevent="datPhong" class="space-y-5 px-6 pb-6">
        @csrf

        {{-- @include('bladethemev1::components.product-detail.booking-summary') --}}

        <hr class="border-[#DDDDDD] -mx-6">

        <input type="hidden" wire:model="startTime" id="startTimeInput">
        <input type="hidden" wire:model="endTime" id="endTimeInput">

        @php
            $canApplyCoupon = $bookingStyle == 2 ? !empty($startTime) && !empty($endTime) : !empty($selectedSlots);
        @endphp

        {{-- Tổng tiền tạm tính (mobile) — reactive qua Alpine @entangle, cập nhật ngay khi Livewire trả về --}}
        <div class="lg:hidden"
            x-data="{ liveTotal: @entangle('totalAmount') }"
            x-show="liveTotal > 0"
            x-cloak>
            <div class="flex items-center justify-between rounded-xl bg-primary/5 px-3.5 py-2.5">
                <span class="text-sm font-bold text-[#222222]">Tổng tiền tạm tính</span>
                <span class="text-base font-bold text-primary"
                    x-text="new Intl.NumberFormat('vi-VN').format(liveTotal) + 'đ'"></span>
            </div>
        </div>

        {{-- Họ và tên --}}
        <div class="space-y-1.5">
            <label for="buyerName" class="block text-[10px] font-semibold tracking-wider uppercase text-[#717171]">Họ
                và tên</label>
            @if ($isAuthUser)
                <div
                    class="w-full border border-green-300 bg-green-50 rounded-lg h-10 px-2.5 flex items-center gap-2.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-green-600 shrink-0" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                    <span class="text-gray-800 text-sm font-medium flex-1">{{ $buyerName }}</span>
                    <span class="text-xs font-medium text-green-700 flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        Đã xác thực
                    </span>
                </div>
            @else
                <input type="text" id="buyerName" wire:model="buyerName" placeholder="Nguyễn Văn A"
                    class="w-full rounded-lg border h-10 px-2.5 text-sm focus:outline-none focus-visible:ring-1 focus-visible:ring-[#222222] {{ $errors->has('buyerName') ? 'border-red-600 border-2' : 'border-[#DDDDDD]' }}" />
            @endif
        </div>

        {{-- Email + Số điện thoại --}}
        <div class="gap-3">
            {{-- <div class="space-y-1.5">
        <label for="buyerEmail" class="block text-[10px] font-semibold tracking-wider uppercase text-[#717171]">Email</label>
        <input
            type="email"
            id="buyerEmail"
            wire:model="buyerEmail"
            placeholder="email@gmail.com"
            class="w-full rounded-lg border h-10 px-2.5 text-sm focus:outline-none focus-visible:ring-1 focus-visible:ring-[#222222] {{ $errors->has('buyerEmail') ? 'border-red-600 border-2' : 'border-[#DDDDDD]' }}"
        />
    </div> --}}
            <div class="space-y-1.5">
                <label for="buyerPhone"
                    class="block text-[10px] font-semibold tracking-wider uppercase text-[#717171]">Số điện
                    thoại</label>
                @if ($isAuthUser)
                    <div
                        class="w-full border border-green-300 bg-green-50 rounded-lg h-10 px-2 flex items-center gap-1.5 overflow-hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-green-600 shrink-0"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" />
                        </svg>
                        <span class="text-gray-800 text-xs font-medium flex-1 truncate">{{ $buyerPhone }}</span>
                    </div>
                @else
                    <input type="text" id="buyerPhone" wire:model="buyerPhone" placeholder="0912 345 678"
                        class="w-full rounded-lg border h-10 px-2.5 text-sm focus:outline-none focus-visible:ring-1 focus-visible:ring-[#222222] {{ $errors->has('buyerPhone') ? 'border-red-600 border-2' : 'border-[#DDDDDD]' }}" />
                @endif
            </div>
        </div>
    

        {{-- Số lượng khách (gọn, ngay dưới số điện thoại) --}}
        <div class="space-y-1.5">
            <label class="block text-[10px] font-semibold tracking-wider uppercase text-[#717171]">Số lượng
                khách</label>
            <div wire:ignore x-data="{
                guestCount: {{ (int) $guests }},
                isOvernight: $wire.entangle('isOvernightBooking'),
                dec() { if (this.guestCount > 1) { this.guestCount--;
                        $wire.set('guests', this.guestCount); } },
                inc() {
                    this.guestCount++;
                    $wire.set('guests', this.guestCount);
                }
            }">
                <div class="flex items-center justify-between rounded-lg border border-[#DDDDDD] h-10 px-2.5">
                    <div class="flex items-center gap-1.5 text-sm text-[#222222]">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="h-3.5 w-3.5 text-[#717171] shrink-0">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        <span>Khách</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="dec()"
                            class="rounded-full border transition-colors border-primary text-primary hover:bg-primary hover:text-white shrink-0"
                            style="display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:22px;height:22px;min-width:22px;min-height:22px;max-width:22px;max-height:22px;padding:0;line-height:1;font-size:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                stroke-linejoin="round" style="display:block;">
                                <path d="M5 12h14"></path>
                            </svg>
                        </button>
                        <span class="text-sm font-semibold text-[#222222] w-3 text-center tabular-nums"
                            x-text="guestCount"></span>
                        <button type="button" @click="inc()"
                            class="rounded-full border transition-colors shrink-0 border-primary text-primary hover:bg-primary hover:text-white"
                            style="display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;width:22px;height:22px;min-width:22px;min-height:22px;max-width:22px;max-height:22px;padding:0;line-height:1;font-size:0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                                stroke-linejoin="round" style="display:block;">
                                <path d="M5 12h14"></path>
                                <path d="M12 5v14"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <p x-show="isOvernight" x-cloak class="text-[11px] text-[#717171] mt-1">* Khung giờ qua đêm cần khai
                    báo lưu trú (CCCD) cho tất cả người đi cùng.</p>
            </div>
        </div>

        <!-- MÃ GIẢM GIÁ (cho phép áp nhiều mã cùng lúc — xem ProductDetail::applyCoupon /
             calculateCouponDiscounts) -->
        <div class="space-y-1.5">
            <label class="block text-[10px] font-semibold tracking-wider uppercase text-[#717171]">Mã giảm
                giá</label>

            {{-- Danh sách mã ĐÃ áp dụng — mỗi mã 1 dòng, riêng nút xoá, số tiền giảm lấy từ
                 $couponDiscounts (đã cascading: mã % tính trước trên số tiền lớn hơn, mã fixed
                 tính sau trên phần còn lại). --}}
            @if (count($appliedCoupons) > 0)
                <div class="space-y-1.5">
                    @foreach ($appliedCoupons as $coupon)
                        <div class="bg-green-50 border border-green-300 rounded-lg p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 mb-1 flex-wrap">
                                        <span
                                            class="inline-block bg-green-500 text-white px-2 py-0.5 rounded-full text-xs font-bold">
                                            {{ $coupon->code }}
                                        </span>
                                        <span class="text-green-700 font-medium text-xs truncate">
                                            {{ $coupon->name }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-700">
                                        @if ($coupon->type === 'percentage')
                                            Giảm {{ $coupon->value }}%
                                            @if ($coupon->max_discount)
                                                (tối đa {{ number_format($coupon->max_discount, 0, ',', '.') }}đ)
                                            @endif
                                        @else
                                            Giảm {{ number_format($coupon->value, 0, ',', '.') }}đ
                                        @endif
                                    </p>
                                    <p class="text-xs font-bold text-green-600 mt-0.5">
                                        Bạn được giảm: {{ number_format($couponDiscounts[$coupon->id] ?? 0, 0, ',', '.') }}đ
                                    </p>
                                </div>
                                <button type="button" wire:click="removeCoupon('{{ $coupon->id }}')"
                                    class="text-red-500 hover:text-red-700 transition p-1 shrink-0" title="Xóa mã giảm giá">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                    @if (count($appliedCoupons) > 1)
                        <p class="text-xs font-bold text-green-600 text-right">
                            Tổng cộng giảm: {{ number_format($couponDiscountAmount, 0, ',', '.') }}đ
                        </p>
                    @endif
                </div>
            @endif

            {{-- Form nhập thêm mã — luôn hiển thị (kể cả khi đã áp mã khác) để cho phép cộng dồn --}}
            <div class="space-y-2">
                {{-- Khách đã đăng nhập: gợi ý sẵn mã giảm giá riêng/được gán (personalCoupons +
                     coupons, xem ProductDetail::loadMyCoupons) — bấm "+" điền mã và áp dụng
                     ngay, không cần gõ tay. Ẩn mã đã áp rồi, chỉ hiện mã đang dùng được. --}}
                @php $appliedCodes = collect($appliedCoupons)->pluck('code')->all(); @endphp
                @if ($isAuthUser && count($myCoupons) > 0 && count(array_diff(array_column($myCoupons, 'code'), $appliedCodes)) > 0)
                    <div class="space-y-1.5 mb-2">
                        @foreach ($myCoupons as $mc)
                            @continue(in_array($mc['code'], $appliedCodes))
                            <div class="flex items-center gap-2 border border-dashed border-primary/40 bg-primary/5 rounded-lg px-2.5 py-1.5">
                                <span class="shrink-0 bg-primary text-white px-2 py-0.5 rounded-full text-[11px] font-bold tracking-wide">
                                    {{ $mc['code'] }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-[#222222] truncate">{{ $mc['name'] ?? 'Mã giảm giá' }}</p>
                                    <p class="text-[11px] text-primary font-semibold">Giảm {{ $mc['value_label'] }}</p>
                                </div>
                                <button type="button" wire:click="quickApplyCoupon('{{ $mc['code'] }}')"
                                    wire:loading.attr="disabled" wire:target="quickApplyCoupon('{{ $mc['code'] }}')"
                                    class="shrink-0 w-7 h-7 flex items-center justify-center rounded-full bg-primary text-white hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    title="Áp dụng mã {{ $mc['code'] }}" @if (!$canApplyCoupon) disabled @endif>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex gap-2 items-stretch">
                    <input type="text" wire:model.defer="couponCode"
                        placeholder="Nhập mã giảm giá"
                        class="flex-1 min-w-0 self-stretch border border-[#DDDDDD] rounded-lg focus:outline-none focus-visible:ring-1 focus-visible:ring-primary focus:border-primary uppercase"
                        style="box-sizing:border-box;padding:0 10px;font-size:11px;text-transform: uppercase;" @if (!$canApplyCoupon) disabled @endif>
                    <button type="button" wire:click="applyCoupon"
                        class="bg-primary text-white rounded-lg font-semibold hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed shrink-0 h-9"
                        style="box-sizing:border-box;padding:0 10px;font-size:11px;white-space:nowrap;line-height:1;"
                        @if (!$canApplyCoupon) disabled @endif>
                        Áp dụng
                    </button>
                </div>

                {{-- Thông báo lỗi --}}
                @if ($couponErrorMessage)
                    <div
                        class="bg-red-50 border border-red-200 text-red-700 px-2.5 py-1.5 rounded-lg text-xs flex items-start gap-1.5">
                        <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                clip-rule="evenodd" />
                        </svg>
                        <span>{{ $couponErrorMessage }}</span>
                    </div>
                @endif

                {{-- Helper text --}}
                @if ($canApplyCoupon)
                    <p class="text-xs text-gray-500">
                        {{ count($appliedCoupons) > 0 ? 'Có thêm mã khác? Nhập và áp dụng để cộng dồn ưu đãi!' : 'Có mã giảm giá? Nhập và áp dụng để được ưu đãi!' }}
                    </p>
                @elseif($bookingStyle == 2)
                    <p class="text-xs text-gray-500 italic">
                        * Vui lòng chọn ngày nhận và trả phòng trước khi áp dụng mã giảm giá
                    </p>
                @else
                    <p class="text-xs text-gray-500 italic">
                        * Vui lòng chọn khung giờ trước khi áp dụng mã giảm giá
                    </p>
                @endif
            </div>
        </div>

        <hr class="border-[#DDDDDD] -mx-6">

        <!-- CCCD -->
        <div>
            <p class="text-xs font-semibold tracking-wider uppercase text-[#717171]">Xác thực danh tính</p>
            <p class="text-[11px] text-[#717171] mt-0.5 mb-3">Bắt buộc để hoàn tất nhận phòng</p>

            @if ($isAuthUser && !empty($authCccdFront) && !empty($authCccdBack))
                {{-- Auth user đã có CCCD trong profile → hiển thị ảnh đã lưu --}}
                <div class="bg-green-50 border border-green-300 rounded-xl p-3 mb-3 flex items-start gap-2">
                    <svg class="w-4 h-4 text-green-600 shrink-0 mt-0.5" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-green-800">CCCD đã xác minh từ hồ sơ của bạn</p>
                        <p class="text-xs text-green-600 mt-0.5">Thông tin CCCD được lấy tự động — không cần upload
                            lại.</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    @foreach ([['front', $authCccdFront, 'Mặt trước'], ['back', $authCccdBack, 'Mặt sau']] as [$side, $path, $label])
                        @php $url = $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : ''; @endphp
                        <div
                            class="relative group flex-1 h-36 border-2 border-green-300 rounded-xl overflow-hidden bg-green-50">
                            <img src="{{ $url }}" alt="{{ $label }} CCCD"
                                class="absolute inset-0 w-full h-full object-cover rounded-xl">
                            {{-- Overlay cho phép upload lại --}}
                            <label
                                class="absolute inset-0 flex flex-col items-center justify-center bg-black/0 group-hover:bg-black/40 transition-all cursor-pointer rounded-xl">
                                <input type="file" accept="image/*" class="hidden"
                                    onchange="processAndUpload(this, 'cccd_{{ $side }}', {maxSize: 2400, quality: 0.92})" />
                                <div class="opacity-0 group-hover:opacity-100 transition-opacity text-center">
                                    <svg class="w-6 h-6 text-white mx-auto mb-1" fill="none" stroke="currentColor"
                                        stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                    </svg>
                                    <span class="text-white text-xs font-semibold">Đổi ảnh</span>
                                </div>
                            </label>
                            {{-- Badge xác nhận --}}
                            <div
                                class="absolute top-2 left-2 bg-green-500 rounded-full px-2 py-0.5 flex items-center gap-1 shadow">
                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor"
                                    stroke-width="2.5" viewBox="0 0 24 24">
                                    <path d="M5 13l4 4L19 7" />
                                </svg>
                                <span class="text-white text-[10px] font-bold">{{ $label }}</span>
                            </div>
                            {{-- JS-managed loading overlay (cho upload mới) --}}
                            <div wire:ignore>
                                <div id="loading-cccd_{{ $side }}"
                                    class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20 rounded-xl flex flex-col items-center justify-center">
                                    <div
                                        class="animate-spin rounded-full h-6 w-6 border-2 border-black border-t-primary mb-2">
                                    </div>
                                    <p class="text-xs font-medium" id="status-cccd_{{ $side }}">Đang xử lý...
                                    </p>
                                    <div class="w-20 h-1 bg-gray-200 rounded-full overflow-hidden mt-1">
                                        <div id="progress-cccd_{{ $side }}"
                                            class="h-full bg-primary rounded-full transition-all" style="width:0%">
                                        </div>
                                    </div>
                                </div>
                                <img id="preview-cccd_{{ $side }}" src=""
                                    class="hidden absolute inset-0 w-full h-full object-cover rounded-xl"
                                    alt="{{ $label }} CCCD mới" />
                                <div id="checkmark-cccd_{{ $side }}"
                                    class="hidden absolute top-2 right-2 bg-green-500 rounded-full p-1 shadow z-10">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor"
                                        stroke-width="2.5" viewBox="0 0 24 24">
                                        <path d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Chưa có CCCD → hiển thị upload zone thông thường --}}
                <div class="grid grid-cols-2 gap-3">
                    @foreach ([['cccd_front', 'Mặt trước CCCD'], ['cccd_back', 'Mặt sau CCCD']] as [$field, $label])
                        <div class="space-y-1.5">
                            <label
                                class="relative flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed cursor-pointer transition-all overflow-hidden py-5 group
                    {{ $errors->has($field) ? 'border-red-600' : 'border-[#DDDDDD] hover:border-[#B0B0B0] bg-[#FAFAFA] hover:bg-[#F7F7F7]' }}">
                                <input type="file" accept="image/*" class="sr-only"
                                    onchange="processAndUpload(this, '{{ $field }}', {maxSize: 2400, quality: 0.92})" />
                                <div wire:ignore class="contents">
                                    <div id="loading-{{ $field }}"
                                        class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20">
                                        <div class="flex flex-col items-center justify-center h-full">
                                            <div
                                                class="animate-spin rounded-full h-8 w-8 border-3 border-black border-t-primary mb-3">
                                            </div>
                                            <p class="text-xs font-medium text-black mb-2"
                                                id="status-{{ $field }}">Đang xử lý...</p>
                                            <div class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                                <div id="progress-{{ $field }}"
                                                    class="h-full bg-primary rounded-full transition-all duration-300"
                                                    style="width: 0%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <img id="preview-{{ $field }}" src=""
                                        class="hidden absolute inset-0 w-full h-full object-cover"
                                        alt="{{ $label }}" />
                                    <div
                                        class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                                        <div class="bg-white/90 rounded-lg px-3 py-1 text-xs font-medium text-black">
                                            Đổi ảnh</div>
                                    </div>
                                    <div id="checkmark-{{ $field }}"
                                        class="hidden absolute top-2 right-2 bg-green-500 rounded-full p-1 shadow-lg z-10">
                                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor"
                                            stroke-width="2.5" viewBox="0 0 24 24">
                                            <path d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                </div>
                                <div id="placeholder-{{ $field }}">
                                    <div
                                        class="h-9 w-9 rounded-full bg-[#F0F0F0] flex items-center justify-center mx-auto">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            class="h-5 w-5 text-[#717171]">
                                            <path
                                                d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z">
                                            </path>
                                            <circle cx="12" cy="13" r="3"></circle>
                                        </svg>
                                    </div>
                                    <div class="text-center mt-2">
                                        <p class="text-xs font-semibold text-[#222222]">{{ $label }}</p>
                                        <p class="text-[11px] text-[#717171] mt-0.5">Nhấn để tải ảnh</p>
                                    </div>
                                </div>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif

            <p class="text-[11px] text-[#717171] mt-2">* Thông tin CCCD được lưu trữ và bảo mật để khai báo lưu trú,
                xóa sau khi check-out.</p>
        </div>

        @if ($this->hasOvernightSlotSelected() && (int) $guests > 1)
            {{-- CCCD người đi cùng — chỉ hiện khi có khung giờ qua đêm (room_time_slots.over_night)
                 VÀ có từ 2 khách trở lên (guests = 1 thì không có người đi cùng nào để khai báo).
                 Quy định khai báo lưu trú yêu cầu CCCD của tất cả người đi cùng. Luôn là upload mới
                 (không có hồ sơ auth để tái dùng như CCCD chính) — 1 cặp ảnh cho mỗi khách từ #2
                 trở đi (số lượng = $guests - 1), xem cccdFrontExtra/cccdBackExtra trong
                 ProductDetail::confirmBooking(). --}}
            <div>
                <p class="text-xs font-semibold tracking-wider uppercase text-[#717171]">CCCD người đi cùng</p>
                <p class="text-[11px] text-[#717171] mt-0.5 mb-3">Khung giờ qua đêm bạn chọn cần khai báo lưu trú
                    cho tất cả người đi cùng theo quy định — vui lòng tải thêm CCCD của từng người.</p>

                @for ($guestIndex = 2; $guestIndex <= (int) $guests; $guestIndex++)
                    @php $companionIdx = $guestIndex - 2; @endphp
                    <div class="mb-4 last:mb-0">
                        <p class="text-[11px] font-semibold text-[#222222] mb-1.5">Người đi cùng #{{ $guestIndex }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ([['cccdFrontExtra.' . $companionIdx, 'Mặt trước CCCD'], ['cccdBackExtra.' . $companionIdx, 'Mặt sau CCCD']] as [$field, $label])
                                <div class="space-y-1.5">
                                    <label
                                        class="relative flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed cursor-pointer transition-all overflow-hidden py-5 group
                            {{ $errors->has($field) ? 'border-red-600' : 'border-[#DDDDDD] hover:border-[#B0B0B0] bg-[#FAFAFA] hover:bg-[#F7F7F7]' }}">
                                        <input type="file" accept="image/*" class="sr-only"
                                            onchange="processAndUpload(this, '{{ $field }}', {maxSize: 2400, quality: 0.92})" />
                                        <div wire:ignore class="contents">
                                            <div id="loading-{{ $field }}"
                                                class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20">
                                                <div class="flex flex-col items-center justify-center h-full">
                                                    <div
                                                        class="animate-spin rounded-full h-8 w-8 border-3 border-black border-t-primary mb-3">
                                                    </div>
                                                    <p class="text-xs font-medium text-black mb-2"
                                                        id="status-{{ $field }}">Đang xử lý...</p>
                                                    <div class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                                        <div id="progress-{{ $field }}"
                                                            class="h-full bg-primary rounded-full transition-all duration-300"
                                                            style="width: 0%"></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <img id="preview-{{ $field }}" src=""
                                                class="hidden absolute inset-0 w-full h-full object-cover"
                                                alt="{{ $label }}" />
                                            <div
                                                class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                                                <div class="bg-white/90 rounded-lg px-3 py-1 text-xs font-medium text-black">
                                                    Đổi ảnh</div>
                                            </div>
                                            <div id="checkmark-{{ $field }}"
                                                class="hidden absolute top-2 right-2 bg-green-500 rounded-full p-1 shadow-lg z-10">
                                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                        </div>
                                        <div id="placeholder-{{ $field }}">
                                            <div
                                                class="h-9 w-9 rounded-full bg-[#F0F0F0] flex items-center justify-center mx-auto">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                    class="h-5 w-5 text-[#717171]">
                                                    <path
                                                        d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z">
                                                    </path>
                                                    <circle cx="12" cy="13" r="3"></circle>
                                                </svg>
                                            </div>
                                            <div class="text-center mt-2">
                                                <p class="text-xs font-semibold text-[#222222]">{{ $label }}</p>
                                                <p class="text-[11px] text-[#717171] mt-0.5">Nhấn để tải ảnh</p>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endfor

                <p class="text-[11px] text-[#717171] mt-2">* CCCD người đi cùng chỉ dùng để khai báo lưu trú, bảo
                    mật như CCCD chính và xóa sau khi check-out.</p>
                <p class="text-[11px] text-red-600 font-medium mt-1">* Khách hàng chịu hoàn toàn trách nhiệm về
                    thông tin CCCD đã cung cấp;
                </p>
            </div>
        @endif

        <textarea id="note" wire:model="note" placeholder="Ghi chú cho 365Home" rows="3"
            class="w-full px-4 py-3 border border-[#DDDDDD] rounded-lg focus:ring-2 focus:ring-primary focus:border-primary focus:outline-none resize-none text-base"></textarea>

        <div class="rounded-xl border border-[#DDDDDD] p-4" x-data="{}">
            <label class="flex items-start gap-2.5 cursor-pointer select-none">
                <input type="checkbox"
                    :checked="$wire.accept1 && $wire.accept2 && $wire.acceptRefundPolicy"
                    @change="$wire.set('accept1', $event.target.checked); $wire.set('accept2', $event.target.checked); $wire.set('acceptRefundPolicy', $event.target.checked)"
                    class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary cursor-pointer shrink-0
                    {{ $errors->has('accept1') || $errors->has('accept2') || $errors->has('acceptRefundPolicy') ? 'border-2 border-red-600' : 'border-gray-300' }}" />
                <span class="text-[11px] text-gray-700 leading-snug">
                    Tôi xác nhận đã đủ 18 tuổi (hoặc có người giám hộ đi cùng), đã đọc và đồng ý với
                    <a href="{{ url('noi-quy-va-quy-dinh') }}" target="_blank" rel="noopener" class="font-semibold text-red-600 underline">Nội quy</a> và
                    <a href="{{ url('privacy') }}" target="_blank" rel="noopener" class="font-semibold text-red-600 underline">Chính sách</a>
                    của 365Home, bao gồm điều kiện hoàn tiền (hoàn từ 70% khi hủy, 10% phí xử lý). Sau khi thanh toán, vui lòng quay lại đây để hoàn tất.
                </span>
            </label>
            @if ($errors->has('accept1') || $errors->has('accept2') || $errors->has('acceptRefundPolicy'))
                <p class="text-red-600 text-[12px] mt-1.5 font-medium">Vui lòng đồng ý với điều khoản trên trước khi đặt phòng.</p>
            @endif
        </div>

        <p class="text-[11px] text-[#717171]">
            * Bạn đang đặt phòng tại 365Home - {{ $categories['c3'] ?? '' }}. Sau khi bấm "Đặt phòng", bạn sẽ được chuyển
            sang quét mã QR để thanh toán, thời gian giữ phòng là 15 phút chờ thanh toán.
        </p>

        <hr class="border-[#DDDDDD] -mx-6">

        <button type="submit" wire:loading.attr="disabled" wire:target="datPhong"
            class="w-full h-12 rounded-xl font-semibold text-base shadow-sm transition-all active:scale-[0.98] bg-primary hover:opacity-90 text-white disabled:opacity-50 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="datPhong">Đặt phòng</span>
            <span wire:loading wire:target="datPhong">Đang xử lý...</span>
        </button>
    </form>

    </div>{{-- /pd-book-sheet-scroll --}}
</div>

<style>
    @media (max-width: 1023px) {
        {{-- Mặc định (không có class pd-sheet-peek/pd-sheet-full) = THU GỌN — chỉ đủ cao cho
             handle + badge "Nhập thông tin đặt phòng" (xem #pd-book-badge), không còn hiện sẵn ở
             45% màn hình như trước. Bấm badge (hoặc handle) mới mở ra .pd-sheet-peek. --}}
        #pd-booking-form {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 40;
            height: calc(64px + var(--pd-bottom-nav-h, 64px));
            min-height: calc(64px + var(--pd-bottom-nav-h, 64px));
            max-height: calc(100vh * 0.9);
            transition: height .32s cubic-bezier(.32, .72, 0, 1);
            background: #fff;
            border-radius: 20px 20px 0 0;
            -webkit-transform: translateZ(0);
            transform: translateZ(0);
        }

        #pd-booking-form.pd-sheet-dragging {
            transition: none;
        }

        #pd-booking-form.pd-sheet-peek {
            height: calc(100vh * 0.45);
            min-height: calc(130px + var(--pd-bottom-nav-h, 64px));
        }

        #pd-booking-form.pd-sheet-full {
            height: calc(100vh * 0.8);
            min-height: calc(130px + var(--pd-bottom-nav-h, 64px));
        }

        #pd-book-card {
            height: 100%;
            display: flex;
            flex-direction: column;
            border-radius: 20px 20px 0 0 !important;
            box-shadow: 0 -8px 30px rgba(0, 0, 0, .18) !important;
        }

        #pd-book-sheet-handle {
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            padding: 10px 0 6px;
            cursor: grab;
            touch-action: none;
            background: #fff;
        }

        #pd-book-sheet-handle::before {
            content: '';
            width: 36px;
            height: 4px;
            border-radius: 3px;
            background: #d1d5db;
        }

        {{-- Badge chỉ hiện ở trạng thái thu gọn mặc định (không có class peek/full) — ẩn ngay khi
             sheet mở ra (peek/full), nhường chỗ cho nội dung form thật (#pd-book-sheet-scroll). --}}
        #pd-book-badge {
            display: none;
            width: 100%;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            border: none;
            background: #fff;
            color: var(--color-primary);
            font-size: 14px;
            font-weight: 700;
            /* Nền pulse nhẹ phía sau chữ — điểm nhấn để khách nhận ra đây là khu vực bấm được,
               không chỉ là dòng chữ mô tả thường. */
            animation: pd-badge-pulse 2s ease-in-out infinite;
        }

        #pd-book-badge-text {
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        #pd-book-badge-chevron {
            animation: pd-badge-chevron-bounce 1.4s ease-in-out infinite;
        }

        @keyframes pd-badge-pulse {
            0%, 100% { background: #fff; }
            50% { background: color-mix(in srgb, var(--color-primary) 8%, #fff); }
        }

        @keyframes pd-badge-chevron-bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        #pd-booking-form:not(.pd-sheet-peek):not(.pd-sheet-full) #pd-book-badge {
            display: flex;
        }

        #pd-booking-form:not(.pd-sheet-peek):not(.pd-sheet-full) #pd-book-sheet-scroll {
            display: none;
        }

        #pd-book-sheet-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            scrollbar-width: none;
            -ms-overflow-style: none;
            background: #fff;
            padding-bottom: var(--pd-bottom-nav-h, 64px);
        }

        #pd-book-sheet-scroll::-webkit-scrollbar {
            display: none;
        }
    }
</style>

@push('scripts')
    <script>
        // ─── Bottom-sheet kéo lên/xuống cho Thông tin đặt phòng (mobile < lg) ──────────
        (function() {
            var sheet = null,
                handle = null,
                dragging = false,
                startY = 0,
                startH = 0,
                containerH = 0;
            var PEEK_RATIO = 0.45;
            var FULL_RATIO = 0.8;

            function isMobile() {
                return window.innerWidth < 1024;
            }

            function navOffset() {
                var v = getComputedStyle(document.documentElement).getPropertyValue('--pd-bottom-nav-h');
                var n = parseFloat(v);
                return isNaN(n) ? 64 : n;
            }

            function availH() {
                return window.innerHeight;
            }

            function measureBottomNav() {
                var nav = document.querySelector('.bottom-navigation-1');
                if (nav && nav.offsetHeight > 0) {
                    document.documentElement.style.setProperty('--pd-bottom-nav-h', nav.offsetHeight + 'px');
                } else {
                    document.documentElement.style.setProperty('--pd-bottom-nav-h', '0px');
                }
            }

            // 3 trạng thái: 'collapsed' (mặc định, không class — chỉ đủ cao cho handle + badge),
            // 'peek' (45%, .pd-sheet-peek), 'full' (80%, .pd-sheet-full — chừa khoảng trống phía
            // trên để tránh bị thanh trình duyệt/tai thỏ trên iPhone che mất nút tắt). Trước đây chỉ có
            // peek/full (peek là mặc định) — giờ thêm collapsed làm mặc định mới, badge "Nhập
            // thông tin đặt phòng" (xem #pd-book-badge) bấm vào mới mở ra peek.
            function setState(state) {
                if (!sheet) return;
                sheet.style.height = '';
                sheet.classList.remove('pd-sheet-peek', 'pd-sheet-full');
                if (state === 'peek') sheet.classList.add('pd-sheet-peek');
                else if (state === 'full') sheet.classList.add('pd-sheet-full');
            }

            function collapsedH() {
                return 64 + navOffset();
            }

            function onStart(clientY) {
                if (!isMobile()) return;
                dragging = true;
                containerH = availH();
                startY = clientY;
                startH = sheet.getBoundingClientRect().height;
                sheet.classList.add('pd-sheet-dragging');
            }

            function onMove(clientY) {
                if (!dragging) return;
                var dy = startY - clientY;
                var newH = startH + dy;
                var minH = collapsedH();
                var maxH = containerH * FULL_RATIO;
                if (newH < minH) newH = minH;
                if (newH > maxH) newH = maxH;
                sheet.style.height = newH + 'px';
            }

            function onEnd() {
                if (!dragging) return;
                dragging = false;
                sheet.classList.remove('pd-sheet-dragging');
                var h = sheet.getBoundingClientRect().height;
                var peekPx = containerH * PEEK_RATIO;
                var fullPx = containerH * FULL_RATIO;
                var midLow = (collapsedH() + peekPx) / 2;
                var midHigh = (peekPx + fullPx) / 2;
                if (h < midLow) setState('collapsed');
                else if (h < midHigh) setState('peek');
                else setState('full');
            }

            function initSheet() {
                sheet = document.getElementById('pd-booking-form');
                handle = document.getElementById('pd-book-sheet-handle');
                var badge = document.getElementById('pd-book-badge');
                if (!sheet || !handle || handle.__pdBound) return;
                handle.__pdBound = true;

                handle.addEventListener('touchstart', function(e) {
                    onStart(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchmove', function(e) {
                    onMove(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchend', onEnd);

                handle.addEventListener('mousedown', function(e) {
                    onStart(e.clientY);
                    function mm(ev) {
                        onMove(ev.clientY);
                    }
                    function mu() {
                        onEnd();
                        document.removeEventListener('mousemove', mm);
                        document.removeEventListener('mouseup', mu);
                    }
                    document.addEventListener('mousemove', mm);
                    document.addEventListener('mouseup', mu);
                });

                // Bấm handle: collapsed/peek → full; full → peek (không tự thu gọn lại về
                // collapsed qua bấm — chỉ vuốt xuống thấp mới thu gọn, tránh thu gọn nhầm ngoài ý
                // muốn khi chỉ định bấm mở rộng thêm).
                handle.addEventListener('click', function() {
                    if (dragging) return;
                    setState(sheet.classList.contains('pd-sheet-full') ? 'peek' : 'full');
                });

                if (badge && !badge.__pdBound) {
                    badge.__pdBound = true;
                    badge.addEventListener('click', function() {
                        setState('full');
                    });
                }

                var closeBtn = document.getElementById('pd-book-sheet-close');
                if (closeBtn && !closeBtn.__pdBound) {
                    closeBtn.__pdBound = true;
                    closeBtn.addEventListener('click', function() {
                        setState('collapsed');
                    });
                }
            }

            // Khi vừa vào trang chi tiết phòng (mobile): nếu đã chọn khung giờ/ngày từ màn hình
            // khác (data-preselected="1", xem $fromBookingPage) thì mở luôn bottom sheet toàn màn
            // hình để khách nhập thông tin ngay; ngược lại cuộn xuống khu vực lịch đặt phòng để
            // khách chọn khung giờ trước.
            function autoRevealBookingStep() {
                if (!isMobile() || !sheet) return;
                if (sheet.getAttribute('data-preselected') === '1') {
                    setState('full');
                } else {
                    var section = document.getElementById('pd-timeslots-section');
                    if (section) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', initSheet);
            document.addEventListener('livewire:navigated', function() {
                if (handle) handle.__pdBound = false;
                initSheet();
            });
            initSheet();

            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(autoRevealBookingStep, 300);
            });
            document.addEventListener('livewire:navigated', function() {
                setTimeout(autoRevealBookingStep, 300);
            });

            document.addEventListener('DOMContentLoaded', measureBottomNav);
            window.addEventListener('resize', measureBottomNav, { passive: true });
            document.addEventListener('livewire:navigated', measureBottomNav);
            measureBottomNav();
            setTimeout(measureBottomNav, 50);
            setTimeout(measureBottomNav, 300);
        })();
    </script>
@endpush
