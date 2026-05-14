@props([
    'style' => 'default',
    'route' => null,
    'darkmode' => false,
    'href' => '#'
])

@php
    $componentName = "bladethemev1::buttons.button_style.$style";
@endphp

<x-dynamic-component {{ $attributes }} :darkmode="$darkmode" :component="$componentName">
    {{$slot}}
</x-dynamic-component>