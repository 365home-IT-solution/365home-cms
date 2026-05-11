@foreach ($variants as $variant)
    <x-filament::input.wrapper :label="$variant->name">
        <x-filament::input.select wire:model="state.{{ $variant->id }}">
            <option value="">Chọn {{ $variant->name }}</option>
            @foreach ($variant->options as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
@endforeach
