<div
    class="bg-white p-0 rounded-2xl lg:rounded-2xl border border-[#DDDDDD] shadow-[0_2px_16px_rgba(0,0,0,0.08)] overflow-hidden">
    <div class="px-6 pt-6 pb-2">
        <p class="text-xl font-semibold text-[#222222]">Thông tin Đặt phòng</p>
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

        {{-- Số lượng khách --}}
        @php
            $cfg = $product->room_config ?? [];
            $maxFreeG = (int) ($cfg['max_free_guests'] ?? 2);
            $feeEachG = (int) ($cfg['extra_guest_fee'] ?? 50000);
        @endphp
        <div wire:ignore x-data="{
            guestCount: {{ (int) $guests }},
            dec() { if (this.guestCount > 1) { this.guestCount--;
                    $wire.set('guests', this.guestCount); } },
            inc() { this.guestCount++;
                $wire.set('guests', this.guestCount); }
        }">
            <div class="flex items-center justify-between rounded-xl border border-[#DDDDDD] px-3.5 py-2.5">
                <div class="flex items-center gap-2 text-sm text-[#222222]">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" class="h-4 w-4 text-[#717171]">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="font-medium">Khách</span>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="dec()"
                        class="h-7 w-7 rounded-full border flex items-center justify-center transition-colors border-[#717171] text-[#717171] hover:border-[#222222] hover:text-[#222222]">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="h-3 w-3">
                            <path d="M5 12h14"></path>
                        </svg>
                    </button>
                    <span class="text-sm font-semibold text-[#222222] w-4 text-center tabular-nums"
                        x-text="guestCount"></span>
                    <button type="button" @click="inc()"
                        class="h-7 w-7 rounded-full border flex items-center justify-center transition-colors border-[#717171] text-[#717171] hover:border-[#222222] hover:text-[#222222]">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" class="h-3 w-3">
                            <path d="M5 12h14"></path>
                            <path d="M12 5v14"></path>
                        </svg>
                    </button>
                </div>
            </div>
            {{-- Ghi chú phụ thu --}}
            {{-- <p class="text-[11px] text-[#717171] mt-1.5">
                * Nếu > {{ $maxFreeG }} khách, phụ thu {{ number_format($feeEachG, 0, ',', '.') }}đ/người thêm,
                tính từ
                người thứ {{ $maxFreeG + 1 }} về sau.
            </p>
            @if ($bookingStyle != 2)
                <p class="text-[11px] text-[#717171] mt-1">* Home chỉ nhận tối đa 2 khách nếu
                    khách book có khung giờ qua đêm.</p>
            @endif --}}
        </div>
        <input type="hidden" wire:model="startTime" id="startTimeInput">
        <input type="hidden" wire:model="endTime" id="endTimeInput">

        <hr class="border-[#DDDDDD] -mx-6">

        @php
            $canApplyCoupon = $bookingStyle == 2 ? !empty($startTime) && !empty($endTime) : !empty($selectedSlots);
        @endphp

        <p class="text-xs font-semibold tracking-wider uppercase text-[#717171]">Thông tin liên hệ</p>

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
        <p class="text-[11px] text-[#717171] -mt-3">
            @if ($isAuthUser)
                * Thông tin số điện thoại được lấy từ tài khoản đã đăng nhập của bạn.
            @else
                * Bạn vui lòng nhập đúng số điện thoại, Home sẽ gửi thông tin check-in qua Zalo ạ
            @endif
        </p>

        <!-- MÃ GIẢM GIÁ -->
        <div class="bg-gray-50 rounded-lg p-4">
            <h3 class="text-xl font-semibold mb-3 flex items-center gap-2">
                <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                </svg>
                Mã giảm giá
            </h3>

            @if ($appliedCoupon)
                {{-- Hiển thị khi đã áp dụng coupon --}}
                <div class="bg-green-50 border-2 border-green-300 rounded-lg p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <span
                                    class="inline-block bg-green-500 text-white px-3 py-1 rounded-full text-sm font-bold">
                                    {{ $appliedCoupon->code }}
                                </span>
                                <span class="text-green-700 font-medium">
                                    {{ $appliedCoupon->name }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-700">
                                @if ($appliedCoupon->type === 'percentage')
                                    Giảm {{ $appliedCoupon->value }}%
                                    @if ($appliedCoupon->max_discount)
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
                        <button type="button" wire:click="removeCoupon"
                            class="text-red-500 hover:text-red-700 transition p-1" title="Xóa mã giảm giá">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            @else
                {{-- Form nhập mã coupon --}}
                <div class="space-y-3">
                    <div class="flex gap-2">
                        <input type="text" wire:model.defer="couponCode"
                            placeholder="Nhập mã giảm giá (VD: SUMMER2024)"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent uppercase"
                            style="text-transform: uppercase;" @if (!$canApplyCoupon) disabled @endif>
                        <button type="button" wire:click="applyCoupon"
                            class="px-6 py-2 bg-primary text-white rounded-lg font-medium hover:bg-opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                            @if (!$canApplyCoupon) disabled @endif>
                            Áp dụng
                        </button>
                    </div>

                    {{-- Thông báo lỗi --}}
                    @if ($couponErrorMessage)
                        <div
                            class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-sm flex items-start gap-2">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd" />
                            </svg>
                            <span>{{ $couponErrorMessage }}</span>
                        </div>
                    @endif

                    {{-- Helper text --}}
                    @if ($canApplyCoupon)
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

        <textarea id="note" wire:model="note" placeholder="Ghi chú cho 365Home" rows="3"
            class="w-full px-4 py-3 border border-black rounded-lg focus:ring-2 focus:ring-primary focus:border-primary focus:outline-none resize-none text-base"></textarea>

        <div class="rounded-xl border border-[#DDDDDD] p-4" x-data="{}">
            <label class="flex items-start gap-2.5 cursor-pointer select-none">
                <input type="checkbox"
                    :checked="$wire.accept1 && $wire.accept2 && $wire.acceptRefundPolicy"
                    @change="$wire.set('accept1', $event.target.checked); $wire.set('accept2', $event.target.checked); $wire.set('acceptRefundPolicy', $event.target.checked)"
                    class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary cursor-pointer shrink-0
                    {{ $errors->has('accept1') || $errors->has('accept2') || $errors->has('acceptRefundPolicy') ? 'border-2 border-red-600' : 'border-gray-300' }}" />
                <span class="text-[13px] text-gray-700 leading-snug">
                    Tôi xác nhận đã đủ 18 tuổi (hoặc có người giám hộ đi cùng), đã đọc và đồng ý với
                    <a href="{{ url('noi-quy-va-quy-dinh') }}" target="_blank" rel="noopener" class="font-semibold text-red-600 underline">Nội quy</a> và
                    <a href="{{ url('chinh-sach-bao-mat-thong-tin') }}" target="_blank" rel="noopener" class="font-semibold text-red-600 underline">Chính sách</a>
                    của 365Home, bao gồm điều kiện hoàn tiền (hoàn 90% khi hủy, 10% phí xử lý). Sau khi thanh toán, vui lòng quay lại đây để hoàn tất.
                </span>
            </label>
            @if ($errors->has('accept1') || $errors->has('accept2') || $errors->has('acceptRefundPolicy'))
                <p class="text-red-600 text-[12px] mt-1.5 font-medium">Vui lòng đồng ý với điều khoản trên trước khi đặt phòng.</p>
            @endif
        </div>

        <p class="text-[11px] text-[#717171]">
            * Bạn đang đặt phòng tại 365Home - {{ $categories['c3'] }}. Sau khi bấm "Đặt phòng", bạn sẽ được chuyển
            sang quét mã QR để thanh toán, thời gian giữ phòng là 5 phút chờ thanh toán.
        </p>

        <hr class="border-[#DDDDDD] -mx-6">

        <button type="submit" wire:loading.attr="disabled" wire:target="datPhong"
            class="w-full h-12 rounded-xl font-semibold text-base shadow-sm transition-all active:scale-[0.98] bg-[#222222] hover:bg-[#111111] text-white disabled:opacity-50 disabled:cursor-not-allowed">
            <span wire:loading.remove wire:target="datPhong">Đặt phòng</span>
            <span wire:loading wire:target="datPhong">Đang xử lý...</span>
        </button>
    </form>

    @include('bladethemev1::components.payments.booking-confirmation-modal')
</div>
