@props([
        'text_size' => 'md:text-lg text-md'
])

<button
    {{ $attributes->merge(['class' => "$text_size text-btn-1 p-0 m-0 border-0 bg-none relative flex font-medium gap-x-2 items-center hover:text-primary"]) }}>
    <p>{{ $slot }}</p>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
        stroke-width="4">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
    </svg>
</button>
