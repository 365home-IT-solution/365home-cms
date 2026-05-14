@props([
    'text_size' => 'md:text-lg text-md'
])

<button {{$attributes->merge(['class' => "$text_size btn-close-1 rounded-md"])}}>
    {{$slot}}
</button>
