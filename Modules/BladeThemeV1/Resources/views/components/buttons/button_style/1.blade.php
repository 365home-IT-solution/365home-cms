@props([
    'padding' => 'px-4 py-2',
    'text_size' => 'md:text-lg text-md'
])

<button {{$attributes->merge(['class' => "$padding $text_size flex text-white justify-center gap-2 items-center shadow-xl bg-primary backdrop-blur-md lg:font-semibold isolation-auto border-primary before:absolute before:w-full before:transition-all before:duration-700 before:hover:w-full before:-left-full before:hover:left-0 before:rounded-full before:bg-white hover:text-primary before:-z-10 before:aspect-square before:hover:scale-150 before:hover:duration-700 relative z-10 overflow-hidden border-2 rounded-md group"])}}>
    {{$slot}}
</button>
