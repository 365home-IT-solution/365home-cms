<div x-data="{ showTooltip: false }">
    @if (isset($route) && $route)
        <a href="{{ $route }}" @if (isset($onclick)) x-on:click.prevent="{{ $onclick }}" @endif
            class="inline-block" @mouseenter="showTooltip = true" @mouseleave="showTooltip = false">
            <button type="button"
                onmouseover="this.style.backgroundColor='{{ $primaryColor }}'; this.style.color='white'"
                onmouseout="this.style.backgroundColor='white'; this.style.color='{{ $primaryColor }}'"
                style="color: {{ $primaryColor }}"
                class="rounded-full h-10 w-10 border bg-white shadow-2xl duration-300 hover:text-white">
                <i class="fas {{ $icon }}"></i>
            </button>
        </a>
    @else
        <button @if (isset($onclick)) x-on:click.prevent="{{ $onclick }}" @endif
            onmouseover="this.style.backgroundColor='{{ $primaryColor }}'; this.style.color='white'"
            onmouseout="this.style.backgroundColor='white'; this.style.color='{{ $primaryColor }}'"
            style="color: {{ $primaryColor }}"
            class="rounded-full h-10 w-10 border bg-white shadow-2xl duration-300 hover:text-white">
            <i class="fas {{ $icon }}"></i>
        </button>
    @endif

    <div x-show="showTooltip" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 transform -translate-y-2"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform -translate-y-2"
        class="absolute @if (isset($isBottom)) top-full mt-2
         @else
             bottom-full mb-2 @endif left-1/2 transform -translate-x-1/2 px-3 py-1 bg-white text-black font-bold text-xs rounded-md whitespace-nowrap"
        style="pointer-events: none;">
        {{ $tooltip }}
    </div>
</div>
