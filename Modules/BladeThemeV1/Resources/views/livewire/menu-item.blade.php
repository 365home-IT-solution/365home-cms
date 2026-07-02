@php
    $menuUrl = fn ($item) => $item->branch_booking_url ?: $item->getFullUrl();

    $isActive = function ($item) use ($menuUrl) {
        $currentUrl = request()->url();
        if ($currentUrl == $menuUrl($item)) {
            return true;
        }
        return $item->children->contains(function ($child) use ($currentUrl, $menuUrl) {
            return $currentUrl == $menuUrl($child) || $child->children->contains(function ($grandchild) use ($currentUrl, $menuUrl) {
                return $currentUrl == $menuUrl($grandchild);
            });
        });
    };
    $menuItemActive = $isActive($menuItem);
@endphp

<li class="relative text-[18px] menu-group @if($depth > 1) p-1 @endif">
    @if ($menuItem->children->isEmpty())
        <a href="{{ $menuUrl($menuItem) }}"
           class="@if($depth === 1)
                    main-menu-item w-full flex items-center justify-between {{$sizeClassess}} {{ $navUppercase }} font-medium pl-[20px] h-[40px] transition-all duration-300 ease-in-out relative {{ $menuItemActive ? 'menu-active' : '' }}
                 @else
                    menu-dropdown-item flex items-center justify-between px-4 py-3 rounded-md transition-all duration-200 {{ $menuItemActive ? 'menu-active' : 'text-gray-700' }}
                 @endif">
            <span class="flex-grow text-left">{{ $menuItem->title }}</span>
            <span class="w-3 h-3"></span>
        </a>
    @else
        <div class="@if($depth === 1)
                    main-menu-item flex items-center justify-between {{ $sizeClassess }} {{ $navUppercase }} font-medium pl-[20px] h-[40px] transition-all duration-300 ease-in-out relative cursor-pointer {{ $menuItemActive ? 'menu-active' : '' }}
                 @else
                    menu-dropdown-item flex items-center justify-between w-full px-4 py-3 transition-all duration-200 rounded-md cursor-pointer {{ $menuItemActive ? 'menu-active' : 'text-gray-700' }}
                 @endif">
            <span class="flex-grow text-left">{{ $menuItem->title }}</span>
            <svg class="w-3 h-3 ms-2.5 @if($depth === 1) menu-arrow-icon @else menu-submenu-arrow @endif" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 4 4 4-4"/>
            </svg>
        </div>

        <div class="@if($depth === 1)
                      menu-dropdown absolute top-full left-0 mt-[1px] z-50
                      opacity-0 invisible menu-group-hover:opacity-100 menu-group-hover:visible
                      transform translate-y-2 menu-group-hover:translate-y-0
                      transition-all duration-300 ease-out
                    @else
                      relative z-50
                      max-h-0 overflow-hidden menu-group-hover:max-h-96
                      transition-all duration-300 ease-in-out
                      bg-white rounded-md shadow-lg
                    @endif
                    divide-y divide-gray-100 rounded-lg font-normal">
            <ul class="text-gray-700 py-2">
                @foreach ($menuItem->children as $child)
                    @livewire('bladethemev1::menu-item', [
                    'menuItem' => $child,
                    'depth' => $depth + 1,
                    'loop' => $loop,
                    'navStyle' => $navStyle,
                    'navSize' => 'sm',
                    'navUppercase' => false
                    ], key($child->id))
                @endforeach
            </ul>
        </div>
    @endif
</li>