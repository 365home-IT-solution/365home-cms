@props([
    'padding' => 'px-4 py-2',
    'text_size' => 'md:text-lg text-md'
])

<button {{ $attributes->merge(['class' => "$padding $text_size btn-storm bg-primary text-white relative flex justify-center items-center border-0 focus:outline-none rounded-lg font-semibold"]) }}">
    {{$slot}}
</button>