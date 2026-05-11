@php
    $logo_size = $headerConfig['logo_size'] ?? '45';
@endphp
<div  x-data="{ isSticky: false }"
      x-init="window.addEventListener('scroll', () => { isSticky = window.scrollY > 50 })"
      :class="{ 'shadow-2xl': isSticky }"
      class="sticky z-20 top-0 transition-all duration-300 ease-in-out">
    <div style="background-color: {{ $headerConfig['background_color'] ?? '#fff' }};">
        <div class="max-w-screen-xl mx-auto">
            <div class='flex flex-wrap items-center justify-between py-3'>
                <div class="flex items-center justify-between w-full md:hidden">
                    <a href="/" class="flex items-end justify-center space-x-3 rtl:space-x-reverse">
                        <img style="height: {{ $logo_size . 'px' }}"
                             src="{{ $this->logo }}" alt="Logo"/>
                    </a>
                    <!-- Nút Toggle Menu -->
                    <button
                            data-drawer-target="drawer-navigation"
                            data-drawer-show="drawer-navigation"
                            data-drawer-placement="left"
                            class="inline-flex border border-primary text-primary hover:text-white hover:bg-primary p-3 rounded-md m-2 text-center text-smfocus:outline-none focus:ring-2 focus:ring-gray-200 transition duration-300 ease-in-out"
                    >
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
                <a href="/" class="hidden md:flex items-end justify-center space-x-3 rtl:space-x-reverse">
                    <img style="height: {{ $logo_size . 'px' }}"
                         src="{{ $this->logo }}" alt="Logo"/>
                </a>

                <div class="hidden md:block">
                    <ul class='flex gap-x-10'>
                        @if(!empty($menu->menuItems))
                            @foreach ($menu->menuItems as $menuItem)
                                @livewire('bladethemev1::menu-item', ['menuItem' => $menuItem, 'depth' => 1, 'loop' =>
                                $loop], key($menuItem->id))
                            @endforeach
                        @endif
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <!-- sidenav -->
</div>
