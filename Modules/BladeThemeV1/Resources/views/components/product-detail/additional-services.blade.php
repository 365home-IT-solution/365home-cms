@if($additionalServices && $additionalServices->count() > 0)
<div class="space-y-4" x-data="{ showAllServices: false }">

    <h2 class="text-[22px] font-semibold text-[#222222]">Dịch vụ thêm</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8">
        @foreach($additionalServices as $index => $service)
            @php
                $qty = $selectedServices[$service->id] ?? 0;
            @endphp
            <div wire:key="service-{{ $service->id }}"
                 class="flex items-start gap-3 py-3"
                 @if($index >= 4) x-show="showAllServices" x-cloak @endif>

                {{-- Icon --}}
                <div class="shrink-0 h-6 w-6 flex items-center justify-center text-[#222222]">
                    @if(!empty($service->image))
                        <div wire:ignore>
                            <img src="{{ asset('storage/' . $service->image) }}"
                                 alt="{{ $service->name }}"
                                 class="h-6 w-6 object-contain">
                        </div>
                    @else
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    @endif
                </div>

                {{-- Tên & Giá --}}
                <div class="flex-1 min-w-0">
                    <p class="text-base text-[#222222] truncate">{{ $service->name }}</p>
                    <p class="text-sm text-[#717171] mt-0.5">{{ number_format($service->price, 0, ',', '.') }}&nbsp;đ / lần</p>
                </div>

                {{-- Nút tăng / giảm --}}
                <div class="flex items-center gap-2 shrink-0">
                    <button
                        wire:click="decrementService({{ $service->id }})"
                        wire:key="decrement-{{ $service->id }}"
                        type="button"
                        @disabled($qty <= 0)
                        class="h-7 w-7 rounded-full border flex items-center justify-center transition-colors {{ $qty > 0 ? 'border-[#222222] text-[#222222] hover:bg-[#F7F7F7]' : 'border-[#B0B0B0] text-[#B0B0B0] cursor-not-allowed' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>

                    <span class="text-base tabular-nums w-5 text-center text-[#717171]">{{ $qty }}</span>

                    <button
                        wire:click="incrementService({{ $service->id }})"
                        wire:key="increment-{{ $service->id }}"
                        type="button"
                        class="h-7 w-7 rounded-full border border-[#222222] text-[#222222] flex items-center justify-center transition-colors hover:bg-[#F7F7F7]">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    @if($additionalServices->count() > 4)
    <button type="button"
            @click="showAllServices = !showAllServices"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-5 h-10 text-sm font-semibold border border-[#222222] text-[#222222] hover:bg-[#F7F7F7] transition-colors">
        <span x-text="showAllServices ? 'Thu gọn' : 'Hiển thị tất cả {{ $additionalServices->count() }} dịch vụ'"></span>
    </button>
    @endif

    @php
        $pdServiceQtyTotal = array_sum($selectedServices ?? []);
        $pdServiceAmountTotal = 0;
        foreach ($selectedServices ?? [] as $svcId => $svcQty) {
            $svc = $additionalServices->firstWhere('id', $svcId);
            if ($svc && $svcQty > 0) { $pdServiceAmountTotal += $svc->price * $svcQty; }
        }
    @endphp
    @if($pdServiceQtyTotal > 0)
    <div class="flex items-center justify-between gap-4 px-1">
        <div class="flex items-baseline gap-2 flex-wrap">
            <span class="text-xs text-[#717171]">Dịch vụ đã chọn ({{ $pdServiceQtyTotal }}):</span>
            <span class="text-base font-bold text-[#222222]">{{ number_format($pdServiceAmountTotal, 0, ',', '.') }}&nbsp;đ</span>
        </div>
    </div>
    @endif

</div>
@endif
