@props([
    'ctaButtonConfig' => [],
])
<a href="{{ $ctaButtonConfig['link'] }}">
    <x-bladethemev1::buttons.button :style="'1'" :text_size="'md:text-md text-sm'">
        {{ $ctaButtonConfig['label'] }}
        </x-bladethemev1:buttons.button>
</a>
