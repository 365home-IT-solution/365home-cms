@props([
    'padding' => 'px-4 py-2',
    'text_size' => 'md:text-lg text-md'
])

<button {{$attributes->merge(['class' => "$padding $text_size outline-btn-1 rounded-md"])}}>
    <span class="text">{{$slot}}</span>
    <span class="circle"></span>
</button>
