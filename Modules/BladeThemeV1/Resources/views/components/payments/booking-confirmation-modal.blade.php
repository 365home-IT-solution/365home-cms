<div
    x-data="{
        show: false,
        loyaltyCount: 0,
        timer: null,
        open(count) {
            this.loyaltyCount = count;
            this.show = true;
            this.timer = setTimeout(() => { this.close(true); }, 3000);
        },
        close(openBooking = false) {
            clearTimeout(this.timer);
            this.show = false;
            if (openBooking) { this.$dispatch('open-booking-modal'); }
        }
    }"
    x-on:open-loyalty-modal.window="open($event.detail.count)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 flex items-center justify-center"
    style="display:none; z-index: 9999;"
>
    <!-- Overlay -->
    <div
        class="absolute inset-0 bg-black bg-opacity-60"
        style="z-index: 0;"
        @click="close(true)"
    ></div>

    <!-- Modal box -->
    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 scale-90"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-90"
        class="relative rounded-2xl overflow-hidden shadow-2xl mx-4"
        style="z-index: 10; max-width: 320px; width: 100%;"
    >
        <!-- Nút X -->
        <button
            @click.stop="close(false); $dispatch('open-booking-modal')"
            type="button"
            class="absolute top-2 right-2 bg-black bg-opacity-50 hover:bg-opacity-80 text-white rounded-full w-8 h-8 flex items-center justify-center transition-all"
            style="border:none; cursor:pointer; z-index: 20;"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <!-- GIF giảm 10% - lần 2 -->
        <img
            x-show="loyaltyCount === 1"
            src="{{ asset('storage/ss10.gif') }}"
            alt="Giảm 10%"
            class="w-full h-auto block"
            style="display:none;">

        <!-- GIF giảm 5% - lần 3 trở lên -->
        <img
            x-show="loyaltyCount >= 2"
            src="{{ asset('storage/ss5.gif') }}"
            alt="Giảm 5%"
            class="w-full h-auto block"
            style="display:none;">

        <!-- Progress bar 3s -->
        <div class="w-full h-1 overflow-hidden bg-black bg-opacity-20">
            <div
                x-show="show"
                class="h-full bg-white"
                style="display:none; animation: loyaltyShrink 3s linear forwards;"
            ></div>
        </div>
    </div>
</div>

<!-- Modal Xác nhận đặt phòng -->
<div    x-data="{ showModal: false }"
        x-show="showModal"
        x-on:open-booking-modal.window="showModal = true"
        x-on:close-booking-modal.window="showModal = false"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true" style="display: none;">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <!-- Background overlay -->
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showModal = false"></div>

        <!-- This element is to trick the browser into centering the modal contents. -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true"></span>

        <!-- Modal panel -->
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full">

            <!-- Close button -->
            <div class="absolute top-0 right-0 pt-4 pr-4">
                <button @click="showModal = false" type="button" class="bg-white rounded-md text-gray-400 hover:text-neutral-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-pink-500">
                    <span class="sr-only">Close</span>
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Modal content -->
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="w-full">
                        <!-- Header -->
                        <div class="text-center mb-6">
                            <h3 class="text-2xl font-bold text-gray-900" id="modal-title">
                                Xác nhận đặt phòng
                            </h3>
                        </div>

                        <!-- Booking details -->
                        <div class="space-y-4">
                            <!-- Customer info -->
                            <ul class="list-none">


                                <li class="px-[16px] py-[12px] border-b border-gray-200">
                                    <span class="text-neutral-900 text-[16px] font-semibold">Tên khách hàng:</span>
                                    <span class="text-[16px]">{{ $buyerName }}</span>
                                </li>
                                <li class="px-[16px] py-[12px] border-b border-gray-200">
                                    <span class="text-neutral-900 text-[16px] font-semibold">Số điện thoại:</span>
                                    <span class="text-[16px]">{{ $buyerPhone }}</span>
                                </li>
                                <li class="px-[16px] py-[12px] border-b border-gray-200">
                                    <span class="text-neutral-900 text-[16px] font-semibold">Chi nhánh:</span>
                                    <span class="text-[16px]">Home - {{ $categories['c3'] }}, {{$categories['c2']}}</span>
                                </li>
                                <li class="px-[16px] py-[12px] border-b border-gray-200">
                                    <span class="text-neutral-900 text-[16px] font-semibold">Tên phòng:</span>
                                    <span class="text-[16px]">{{ $product['name'] }}</span>
                                </li>

                                @php
                                    $modalServiceTotal = 0;
                                    if (!empty($selectedServices) && $additionalServices) {
                                        foreach ($selectedServices as $id => $qty) {
                                            $svc = $additionalServices->firstWhere('id', $id);
                                            if ($svc && $qty > 0) {
                                                $modalServiceTotal += $svc->price * $qty;
                                            }
                                        }
                                    }
                                @endphp

                                <!-- Thời gian đặt phòng -->
                                <li class="px-[16px] py-[12px] border-b border-gray-200">
                                    @if($bookingStyle == 2)
                                        {{-- Style=2: hiển thị ngày nhận/trả phòng --}}
                                        @php
                                            $ciMod = !empty($startTime) ? \Carbon\Carbon::parse($startTime)->startOfDay() : null;
                                            $coMod = !empty($endTime)   ? \Carbon\Carbon::parse($endTime)->startOfDay()   : null;
                                            $nightsMod = ($ciMod && $coMod) ? $ciMod->diffInDays($coMod) : 0;
                                            $ci2TimeModal = $style2CheckinTime  ?? '14:00';
                                            $co2TimeModal = $style2CheckoutTime ?? '12:00';
                                        @endphp
                                        <div class="flex items-center justify-between mb-3">
                                            <span class="text-neutral-900 text-[16px] font-semibold">Thời gian ở:</span>
                                            @if($customerOrderCount >= 1)
                                                <div class="text-right">
                                                    <span class="inline-flex items-center gap-1 bg-green-50 border border-green-200 text-green-700 text-[12px] font-semibold px-2 py-1 rounded-full">
                                                        <img src="https://cdn-icons-png.flaticon.com/128/3656/3656855.png" alt="gift" class="w-4 h-4 object-contain">
                                                        Giảm thêm {{ $customerOrderCount === 1 ? '10%' : '5%' }}
                                                    </span>
                                                    <div class="text-[11px] text-gray-400 italic mt-1">
                                                        {{ $customerOrderCount === 1 ? 'Ưu đãi dành riêng cho khách quay lại' : 'Quà tri ân khách hàng thân thiết' }}
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                        @if($ciMod && $coMod)
                                            <div class="grid grid-cols-2 gap-2">
                                                <div class="bg-gray-50 rounded-lg p-3 text-center border border-gray-200">
                                                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Nhận phòng</p>
                                                    <p class="text-base font-bold text-gray-900">{{ $ci2TimeModal }}</p>
                                                    <p class="text-xs text-gray-500 mt-0.5">{{ $ciMod->format('d/m/Y') }}</p>
                                                </div>
                                                <div class="bg-gray-50 rounded-lg p-3 text-center border border-gray-200">
                                                    <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Trả phòng</p>
                                                    <p class="text-base font-bold text-gray-900">{{ $co2TimeModal }}</p>
                                                    <p class="text-xs text-gray-500 mt-0.5">{{ $coMod->format('d/m/Y') }}</p>
                                                </div>
                                            </div>
                                            <p class="text-center text-sm font-semibold mt-2" style="color:#4e6b4c">{{ $nightsMod }} đêm</p>
                                        @else
                                            <span class="text-[14px] text-gray-500 italic">Chưa chọn ngày</span>
                                        @endif
                                    @else
                                        {{-- Style=1: hiển thị danh sách khung giờ --}}
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-neutral-900 text-[16px] font-semibold">Khung giờ đã chọn:</span>
                                            @if($customerOrderCount >= 1)
                                                <div class="text-right">
                                                    <span class="inline-flex items-center gap-1 bg-green-50 border border-green-200 text-green-700 text-[12px] font-semibold px-2 py-1 rounded-full">
                                                        <img src="https://cdn-icons-png.flaticon.com/128/3656/3656855.png" alt="gift" class="w-4 h-4 object-contain">
                                                        Giảm thêm {{ $customerOrderCount === 1 ? '10%' : '5%' }}
                                                    </span>
                                                    <div class="text-[11px] text-gray-400 italic mt-1">
                                                        {{ $customerOrderCount === 1 ? 'Ưu đãi dành riêng cho khách quay lại' : 'Quà tri ân khách hàng thân thiết' }}
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                        @if(!empty($selectedSlots))
                                            <div class="space-y-2">
                                                @foreach($selectedSlots as $index => $slot)
                                                    @php
                                                        $checkinDateTime  = \Carbon\Carbon::parse("{$slot['date']} {$slot['startTime']}");
                                                        $checkoutDateTime = \Carbon\Carbon::parse("{$slot['date']} {$slot['endTime']}");
                                                        if (isset($slot['overNight']) && $slot['overNight'] == 1) {
                                                            $checkoutDateTime->addDay();
                                                        }
                                                    @endphp
                                                    <div class="bg-gray-50 p-2 rounded text-[14px]">
                                                        <div class="flex justify-between items-start mb-1">
                                                            <span class="font-medium">{{ $index + 1 }}. {{ $slot['timeslotLabel'] ?? ($slot['startTime'] . ' - ' . $slot['endTime']) }}</span>
                                                            <span class="text-primary font-semibold">{{ number_format($slot['price'], 0, ',', '.') }}đ</span>
                                                        </div>
                                                        <div class="text-[13px] text-gray-600">
                                                            {{ $checkinDateTime->format('d/m/Y H:i') }} — {{ $checkoutDateTime->format('d/m/Y H:i') }}
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-[14px] text-gray-500 italic">Chưa chọn khung giờ</span>
                                        @endif
                                    @endif
                                </li>

@if(!empty($selectedSlots) && count($selectedSlots) >= 2 || $customerOrderCount >= 1 || (!$hasFullDayBooking && $appliedCoupon && $couponDiscountAmount > 0))
    <li class="px-[16px] py-[8px] border-b border-gray-100">
        <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-[13px] text-blue-800 space-y-1">
            <p class="font-semibold text-blue-900 mb-2">📋 Cách tính giá:</p>

            {{-- Bước 1: Giá gốc --}}
            <div class="flex justify-between">
                <span>① Giá gốc</span>
                <span class="font-semibold">{{ number_format($originalTotalAmount, 0, ',', '.') }}đ</span>
            </div>

            {{-- Bước 2: Giảm theo số khung giờ --}}
            @if(!empty($selectedSlots) && count($selectedSlots) >= 2 && $bulkDiscountAmount > 0)
                @php
                    $slotCount = count($selectedSlots);
                    $rules = $product->bulk_discount_rules ?? [];
                    $matchedRule = collect($rules)
                        ->filter(fn($r) => $slotCount >= (int)($r['slots'] ?? 0))
                        ->sortByDesc('slots')
                        ->first();
                    $bulkRateLabel = $matchedRule ? $matchedRule['discount'] . '%' : '';
                @endphp
                <div class="flex justify-between text-green-700">
                    <span>② {{ $bulkRateLabel ? "Giảm {$bulkRateLabel}" : 'Giảm' }} khi book {{ $slotCount }} khung giờ
                        <span class="text-gray-400 italic text-[11px]">(tính trên giá gốc)</span>
                    </span>
                    <span class="font-semibold">-{{ number_format($bulkDiscountAmount, 0, ',', '.') }}đ</span>
                </div>
            @endif

            {{-- Bước 3: Giảm khách quay lại / thân thiết --}}
            @if($customerOrderCount >= 1)
                @php $step = (!empty($selectedSlots) && count($selectedSlots) >= 2) ? '③' : '②'; @endphp
                <div class="flex justify-between text-green-700">
                    <span>{{ $step }} Giảm thêm {{ $customerOrderCount === 1 ? '10%' : '5%' }}
                        cho khách {{ $customerOrderCount === 1 ? 'quay lại' : 'thân thiết' }}
                        <span class="text-gray-400 italic text-[11px]">(tính sau bước trước)</span>
                    </span>
                    <span class="font-semibold">-{{ number_format($bulkDiscountAmount, 0, ',', '.') }}đ</span>
                </div>
            @endif

            {{-- Bước thêm: Mã giảm giá --}}
            @if(!$hasFullDayBooking && $appliedCoupon && $couponDiscountAmount > 0)
                @php
                    $couponStep = '②';
                    $stepNum = 2;
                    if (!empty($selectedSlots) && count($selectedSlots) >= 2) $stepNum++;
                    if ($customerOrderCount >= 1) $stepNum++;
                    $couponStep = ['②','③','④','⑤'][$stepNum - 2] ?? '④';
                @endphp
                <div class="flex justify-between text-green-700">
                    <span>{{ $couponStep }} Mã giảm giá
                        <span class="font-mono bg-green-100 text-green-800 px-1 rounded text-[11px]">{{ $appliedCoupon->code }}</span>
                        <span class="text-gray-400 italic text-[11px]">(tính sau bước trước)</span>
                    </span>
                    <span class="font-semibold">-{{ number_format($couponDiscountAmount, 0, ',', '.') }}đ</span>
                </div>
            @endif

            {{-- Tổng tiết kiệm --}}
            @php
                $totalSaved = $promoDiscountAmount + $bulkDiscountAmount + $couponDiscountAmount;
            @endphp
            @if($totalSaved > 0)
                <div class="border-t border-blue-200 pt-2 mt-1 flex justify-between text-blue-900 font-semibold">
                    <span>💰 Tổng tiết kiệm</span>
                    <span class="text-green-700">-{{ number_format($totalSaved, 0, ',', '.') }}đ</span>
                </div>
            @endif

            {{-- Thành tiền --}}
            <div class="flex justify-between text-blue-900 font-bold text-[14px] border-t border-blue-200 pt-2">
                <span>✅ Thành tiền</span>
                <span class="text-primary">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
            </div>
        </div>
    </li>
@endif
                                @if($bulkDiscountAmount > 0 && count($selectedSlots) >= 2)
                                    <li class="px-[16px] py-[8px] border-b border-gray-200">
                                        <div class="text-[15px] text-green-600">
                                            Giảm (book {{ count($selectedSlots) }} khung giờ): -{{ number_format($bulkDiscountAmount, 0, ',', '.') }}đ
                                        </div>
                                    </li>
                                @endif
                                @if ($extraFee > 0)
                                    @php $mfgModal = (int)(($product->room_config ?? [])['max_free_guests'] ?? 2); @endphp
                                    <li class="px-[16px] py-[12px] border-b border-gray-200">
                                        <span class="text-neutral-900 text-[16px] font-semibold">Phụ phí khách thêm ({{ max(0, $guests - $mfgModal) }} người):</span>
                                        <span class="text-orange-600 font-semibold">+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
                                    </li>
                                @endif
      @if($additionalServices && $additionalServices->count() > 0)                         
       {{-- Tổng dịch vụ đã chọn --}}
        @if($modalServiceTotal > 0)
            <div class="mt-3 pt-2 border-t border-gray-100 flex items-center justify-between">
                <p class="text-sm text-gray-500">
                    🛎️ {{ array_sum($selectedServices ?? []) }} dịch vụ đã chọn
                </p>
                <p class="text-sm font-bold text-orange-500">
                    +{{ number_format($modalServiceTotal, 0, ',', '.') }}đ
                </p>
            </div>

            {{-- Chi tiết từng dịch vụ đã chọn --}}
            <div class="mt-2 space-y-1">
                @foreach($additionalServices as $service)
                    @php $qty = $selectedServices[$service->id] ?? 0; @endphp
                    @if($qty > 0)
                        <div wire:key="modal-detail-{{ $service->id }}" class="flex items-center justify-between text-[12px] text-gray-600 ml-2">
                            <span>• {{ $service->name }} x{{ $qty }}</span>
                            <span class="text-orange-500 font-medium">
                                +{{ number_format($service->price * $qty, 0, ',', '.') }}đ
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

<div class="overflow-x-auto pb-2">
    <div style="display: flex; gap: 12px;">
        @foreach($additionalServices as $service)
            @php
                $qty = $selectedServices[$service->id] ?? 0;
                $isSelected = $qty > 0;
            @endphp
            <div wire:key="modal-service-{{ $service->id }}" style="width: 105px; flex-shrink: 0;"
                 class="rounded-2xl border-2 flex flex-col items-center py-3 px-2 gap-1 transition-all duration-200
                {{ $isSelected ? 'border-orange-400 bg-orange-50 shadow-md' : 'border-gray-200 bg-white' }}">

                {{-- Icon --}}
                <div class="w-20 h-20 rounded-xl flex items-center justify-center transition-colors duration-200
                    {{ $isSelected ? 'bg-orange-500' : 'bg-orange-100' }}">
                    @if(!empty($service->image))
                      <div wire:ignore>
            <img src="{{ asset('storage/' . $service->image) }}"
                 alt="{{ $service->name }}"
                 class="w-full h-full object-contain">
        </div>
                    @else
                        <span class="text-xl leading-none">{{ $service->image ?? '🍽️' }}</span>
                    @endif
                </div>

                <p class="text-[11px] font-semibold text-gray-700 text-center leading-tight">
                    {{ $service->name }}
                </p>
                <p class="text-[11px] text-gray-400 font-medium">
                    {{ number_format($service->price, 0, ',', '.') }}đ
                </p>

                {{-- Tăng / Giảm --}}
                <div class="flex items-center justify-center gap-1 mt-1 w-full">
                    <button
                        wire:click="decrementService({{ $service->id }})"
                        type="button"
                        style="width:20px;height:20px;min-width:20px;padding:0;box-sizing:border-box;"
                        class="shrink-0 rounded-full border border-gray-300 text-gray-500 text-xs font-bold
                               flex items-center justify-center leading-none
                               hover:bg-orange-500 hover:text-white hover:border-orange-500 transition-all">
                        −
                    </button>
                    <span class="text-xs font-bold text-gray-800 w-4 text-center shrink-0">{{ $qty }}</span>
                    <button
                        wire:click="incrementService({{ $service->id }})"
                        type="button"
                        style="width:20px;height:20px;min-width:20px;padding:0;box-sizing:border-box;"
                        class="shrink-0 rounded-full border border-orange-400 text-orange-500 text-xs font-bold
                               flex items-center justify-center leading-none
                               hover:bg-orange-500 hover:text-white transition-all">
                        +
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
                               

                                <li class="px-[16px] py-[12px] border-b-2 border-gray-300">
                                    @if($bookingStyle == 2 && !empty($startTime) && !empty($endTime))
                                        @php
                                            $ciModal = \Carbon\Carbon::parse($startTime)->startOfDay();
                                            $coModal = \Carbon\Carbon::parse($endTime)->startOfDay();
                                            $nModal  = $ciModal->diffInDays($coModal);
                                            $dpMm       = (int)($product->deposit_multi_night ?? 50);
                                            $minNM      = (int)($product->deposit_min_nights ?? 2);
                                            // Có cọc khi: minNights > 0 VÀ số đêm >= ngưỡng
                                            $dpPctBase  = ($minNM > 0 && $nModal >= $minNM) ? $dpMm : 100;
                                            $showDepositChoice = ($minNM > 0 && $nModal >= $minNM && $dpMm < 100);
                                            // tính theo paymentOption
                                            $effectivePct = ($paymentOption === 'full') ? 100 : $dpPctBase;
                                            $depositAmtModal  = (int) round($totalAmount * $effectivePct / 100);
                                            $remainingModal   = $totalAmount - $depositAmtModal;
                                        @endphp

                                        {{-- Chọn hình thức thanh toán (chỉ hiện khi >= 2 đêm và có cọc) --}}
                                        @if($showDepositChoice)
                                        <p class="text-sm font-semibold text-gray-700 mb-2">Hình thức thanh toán:</p>
                                        <div class="flex gap-3 mb-3">
                                            <label class="flex-1 flex items-center gap-2 p-3 rounded-xl border-2 cursor-pointer transition-all
                                                {{ $paymentOption === 'deposit' ? 'border-amber-400 bg-amber-50' : 'border-gray-200 bg-white' }}">
                                                <input type="radio" wire:model.live="paymentOption" value="deposit" class="sr-only">
                                                <span class="text-lg">💰</span>
                                                <div>
                                                    <p class="text-sm font-bold {{ $paymentOption === 'deposit' ? 'text-amber-700' : 'text-gray-700' }}">Cọc {{ $dpMm }}%</p>
                                                    <p class="text-xs {{ $paymentOption === 'deposit' ? 'text-amber-600' : 'text-gray-400' }}">{{ number_format(round($totalAmount * $dpMm / 100), 0, ',', '.') }}đ trước</p>
                                                </div>
                                            </label>
                                            <label class="flex-1 flex items-center gap-2 p-3 rounded-xl border-2 cursor-pointer transition-all
                                                {{ $paymentOption === 'full' ? 'border-primary bg-green-50' : 'border-gray-200 bg-white' }}">
                                                <input type="radio" wire:model.live="paymentOption" value="full" class="sr-only">
                                                <span class="text-lg">✅</span>
                                                <div>
                                                    <p class="text-sm font-bold {{ $paymentOption === 'full' ? 'text-green-700' : 'text-gray-700' }}">Thanh toán 100%</p>
                                                    <p class="text-xs {{ $paymentOption === 'full' ? 'text-green-600' : 'text-gray-400' }}">{{ number_format($totalAmount, 0, ',', '.') }}đ</p>
                                                </div>
                                            </label>
                                        </div>
                                        @endif

                                        @if($effectivePct < 100)
                                            <div class="space-y-1.5">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-gray-500 text-[14px]">Tổng tiền phòng:</span>
                                                    <span class="font-semibold text-gray-700 text-[14px]">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                                                </div>
                                                <div class="rounded-lg px-3 py-2" style="background:#fffbeb;border:1px solid #fbbf24">
                                                    <div class="flex justify-between items-center">
                                                        <span class="text-amber-700 font-bold text-[15px]">Tiền cọc cần thanh toán ({{ $effectivePct }}%):</span>
                                                        <span class="text-amber-700 font-extrabold text-[18px]">{{ number_format($depositAmtModal, 0, ',', '.') }}đ</span>
                                                    </div>
                                                    <p class="text-xs text-amber-600 mt-1">
                                                        💡 Còn lại {{ number_format($remainingModal, 0, ',', '.') }}đ thanh toán khi nhận phòng
                                                    </p>
                                                </div>
                                                <p class="text-[11px] text-gray-400 italic text-center">Mã cổng được gửi sau khi thanh toán đủ</p>
                                            </div>
                                        @else
                                            <div>
                                                <span class="text-neutral-900 text-[17px] font-bold">Tổng thanh toán:</span>
                                                <span class="text-[18px] font-bold text-primary">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                                            </div>
                                            <p class="text-[11px] text-gray-400 italic text-center mt-1">Mã cổng được gửi ngay sau khi thanh toán đủ</p>
                                        @endif
                                    @else
                                        <span class="text-neutral-900 text-[17px] font-bold">Số tiền tạm tính:</span>
                                        <span class="text-[18px] font-bold text-primary">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                                    @endif
                                </li>



                                @if($note)
                                    <li class="px-[16px] py-[12px] border-t border-gray-200 pt-3">
                                        <p class="text-[16px] text-black font-semibold mb-1">Ghi chú:</p>
                                        <p class="text-[16px] bg-gray-50 p-2 rounded">{{ $note }}</p>
                                    </li>
                                @endif
                            </ul>

                            @php
                                $hasOvernight = !empty($selectedSlots) && collect($selectedSlots)->contains(fn($slot) => isset($slot['overNight']) && $slot['overNight'] == 1);
                            @endphp

                            @if($hasOvernight)
                                <em style="color: #ffffff;font-size:13px;width:100%;display:block;text-align:center;border-radius:20px;padding:8px;" class="bg-primary">Bạn đang book có khung giờ qua đêm nên Home chỉ nhận tối đa 2 khách</em>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse space-y-3 sm:space-y-0 sm:space-x-3 sm:space-x-reverse relative">

                {{-- Loading overlay --}}
                <div wire:loading wire:target="confirmBooking"
                     class="absolute inset-0 bg-white/80 backdrop-blur-sm flex flex-col items-center justify-center gap-2 z-10 rounded-b-lg">
                    <svg class="animate-spin w-7 h-7 text-primary" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                    <span class="text-sm font-semibold text-primary">Đang kiểm tra khung giờ...</span>
                </div>

                <button wire:click="confirmBooking"
                        wire:loading.attr="disabled"
                        wire:target="confirmBooking"
                        type="button"
                        class="w-full inline-flex items-center justify-center gap-2 rounded-full border border-primary shadow-sm px-4 py-3 bg-primary text-base font-medium text-white hover:opacity-80 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-lg disabled:opacity-60 disabled:cursor-not-allowed">
                    <svg wire:loading wire:target="confirmBooking" class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                    <span wire:loading.remove wire:target="confirmBooking">Đặt phòng</span>
                    <span wire:loading wire:target="confirmBooking">Đang xử lý...</span>
                </button>

                <button @click="showModal = false"
                        wire:loading.attr="disabled"
                        wire:target="confirmBooking"
                        type="button"
                        class="w-full inline-flex justify-center rounded-full border border-primary shadow-sm px-4 py-3 bg-white text-base font-medium text-primary hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary sm:mt-0 sm:w-auto sm:text-lg disabled:opacity-40 disabled:cursor-not-allowed">
                    Sửa lại thông tin
                </button>
            </div>
        </div>
    </div>
</div>