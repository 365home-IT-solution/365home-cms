@if($additionalServices && $additionalServices->count() > 0)
<div class="mt-6 mb-6">

    <h3 class="text-base font-extrabold text-gray-800 uppercase tracking-widest mb-4">
        Dịch vụ thêm
    </h3>

    <div class="flex gap-3 overflow-x-auto pb-2">
        @foreach($additionalServices as $service)
            @php
                $qty = $selectedServices[$service->id] ?? 0;
                $isSelected = $qty > 0;
            @endphp

            {{-- ✅ Thêm wire:key để Livewire track đúng từng card --}}
            <div wire:key="service-{{ $service->id }}"
                 class="flex-shrink-0 w-[115px] rounded-2xl border-2 flex flex-col items-center py-4 px-2 gap-2 transition-all duration-200 {{ $isSelected ? 'bg-white shadow-md' : 'border-gray-200 bg-white' }}"
                 style="{{ $isSelected ? 'border-color: var(--color-primary);' : '' }}">

                {{-- Icon --}}
                <div class="w-full aspect-square rounded-xl flex items-center justify-center overflow-hidden transition-colors duration-200
                    {{ $isSelected ? 'bg-transparent' : 'bg-transparent' }}">
                    @if(!empty($service->image))
                        {{-- ✅ Thêm wire:ignore để Livewire không động vào img tag --}}
                        <div wire:ignore>
                            <img src="{{ asset('storage/' . $service->image) }}"
                                 alt="{{ $service->name }}"
                                 class="w-full h-full object-contain {{ $isSelected ? 'brightness-0 invert' : '' }}">
                        </div>
                    @else
                        <span class="text-2xl leading-none">🍽️</span>
                    @endif
                </div>

                {{-- Tên & Giá --}}
                <p class="text-xs font-semibold text-gray-700 text-center leading-tight">
                    {{ $service->name }}
                </p>
                <p class="text-xs text-gray-400 font-medium">
                    {{ number_format($service->price, 0, ',', '.') }}đ
                </p>

                {{-- Nút tăng / giảm --}}
                <div class="flex items-center gap-2 mt-1 px-2 w-full justify-center">
                    <button
                        wire:click="decrementService({{ $service->id }})"
                        wire:key="decrement-{{ $service->id }}"
                        type="button"
                        class="w-6 h-6 rounded-full border border-gray-300 text-gray-500
                               flex items-center justify-center transition-all"
                        onmouseover="this.style.backgroundColor='var(--color-primary)';this.style.borderColor='var(--color-primary)';this.style.color='white'"
                        onmouseout="this.style.backgroundColor='';this.style.borderColor='';this.style.color=''">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>

                    <span class="text-sm font-bold text-gray-800 w-4 text-center">
                        {{ $qty }}
                    </span>

                    <button
                        wire:click="incrementService({{ $service->id }})"
                        wire:key="increment-{{ $service->id }}"
                        type="button"
                        class="w-6 h-6 rounded-full text-white
                               flex items-center justify-center transition-all"
                        style="background-color: var(--color-primary); border: 1px solid var(--color-primary);"
                        onmouseover="this.style.opacity='0.85'"
                        onmouseout="this.style.opacity='1'">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                </div>
            </div>
        @endforeach
    </div>

</div>
@endif