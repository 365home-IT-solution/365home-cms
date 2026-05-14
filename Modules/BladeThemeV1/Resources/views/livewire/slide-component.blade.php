<div>
    @if(!empty($image_event_slide))
    <div class="flex justify-center mb-4">
        <div class="w-full md:w-[380px] md:h-[120px] overflow-hidden">
            <img src="{{ asset('storage/' . $image_event_slide) }}" alt="Sự kiện" class="w-full h-auto md:h-full md:object-cover block" loading="lazy">
        </div>
    </div>
    @endif

    @switch($style)
        @case('categories')
            @livewire('bladethemev1::categories', ['config' => $config, 'uniqueId' => $uniqueId])
            @break

        @case('products')
            @livewire('bladethemev1::products', ['config' => $config, 'uniqueId' => $uniqueId])
            @break

        @case('settings')
            @livewire('bladethemev1::settings', ['config' => $config, 'uniqueId' => $uniqueId, 'settings' => $settings])
            @break

        @case('maps')
            @livewire('bladethemev1::maps', ['config' => $config, 'uniqueId' => $uniqueId])
            @break

        @default
            <div>Component kiểu "{{ $style }}" không được hỗ trợ.</div>
    @endswitch
</div>
