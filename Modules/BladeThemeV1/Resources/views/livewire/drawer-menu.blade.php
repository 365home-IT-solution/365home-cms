<div id="drawer-navigation"
     class="fixed lg:hidden z-50 h-screen overflow-y-auto bg-gradient-to-br from-slate-50 via-white to-slate-100 w-full right-0 transform translate-x-full transition-transform duration-300 ease-in-out shadow-2xl border-l border-gray-200">

    <div class="bg-primary text-white shadow-lg relative flex items-center justify-between px-4 py-4">
        <!-- Số điện thoại với SVG -->
        <div id="drawer-navigation-label"
             class="text-lg font-semibold flex items-center gap-2 ">
            <!-- SVG điện thoại -->
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                 xmlns="http://www.w3.org/2000/svg">
                <path d="M11.0438 14.6662H11.0625C11.4145 14.6662 11.7472 14.5275 11.9992 14.2755L13.8072 12.4675C13.8691 12.4056 13.9183 12.3322 13.9518 12.2513C13.9854 12.1704 14.0026 12.0837 14.0026 11.9962C14.0026 11.9086 13.9854 11.8219 13.9518 11.741C13.9183 11.6602 13.8691 11.5867 13.8072 11.5248L11.1405 8.85816C11.0786 8.7962 11.0052 8.74704 10.9243 8.7135C10.8434 8.67996 10.7567 8.6627 10.6692 8.6627C10.5816 8.6627 10.4949 8.67996 10.414 8.7135C10.3331 8.74704 10.2597 8.7962 10.1978 8.85816L9.13515 9.92083C8.64249 9.77416 7.72315 9.44083 7.14049 8.85816C6.55782 8.2755 6.22449 7.35616 6.07782 6.8635L7.14049 5.80083C7.20245 5.73897 7.25161 5.6655 7.28515 5.58462C7.31869 5.50375 7.33595 5.41705 7.33595 5.3295C7.33595 5.24194 7.31869 5.15524 7.28515 5.07437C7.25161 4.99349 7.20245 4.92002 7.14049 4.85816L4.47382 2.1915C4.34637 2.0712 4.17774 2.00419 4.00249 2.00419C3.82723 2.00419 3.6586 2.0712 3.53115 2.1915L1.72382 3.9995C1.47049 4.25283 1.32782 4.60083 1.33315 4.95616C1.34849 5.9055 1.59982 9.20283 4.19849 11.8015C6.79715 14.4002 10.0945 14.6508 11.0438 14.6662ZM4.00315 3.6055L5.72715 5.3295L4.86515 6.1915C4.78671 6.26972 4.72908 6.36633 4.69753 6.47253C4.66597 6.57872 4.66149 6.69113 4.68449 6.7995C4.70049 6.87616 5.09182 8.69416 6.19849 9.80083C7.30515 10.9075 9.12315 11.2988 9.19982 11.3148C9.30819 11.3379 9.42062 11.3335 9.52684 11.302C9.63305 11.2704 9.72966 11.2127 9.80782 11.1342L10.6698 10.2722L12.3938 11.9962L11.0565 13.3328C10.2245 13.3188 7.37782 13.0955 5.14115 10.8582C2.89715 8.61416 2.67982 5.7575 2.66649 4.94216L4.00315 3.6055ZM13.3325 7.33283H14.6658C14.6658 3.91283 12.0838 1.3335 8.65915 1.3335V2.66683C11.3672 2.66683 13.3325 4.62883 13.3325 7.33283Z" fill="white"/>
                <path d="M8.66602 5.33333C10.068 5.33333 10.666 5.93133 10.666 7.33333H11.9993C11.9993 5.18333 10.816 4 8.66602 4V5.33333Z" fill="white"/>
            </svg>
            <span>0939 174 365</span>
        </div>

        <!-- Nút đóng (icon SVG X đơn giản) -->
        <button type="button" data-drawer-hide="drawer-navigation" aria-controls="drawer-navigation"
                aria-label="Đóng menu điều hướng"
                class="inline-flex border border-info text-info p-2 rounded-md text-center focus:outline-none focus:ring-2 focus:ring-gray-200 transition duration-300 ease-in-out min-w-[30px] min-h-[30px]">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="overflow-y-auto h-full pb-20 px-2">
        <ul class="font-medium py-4 space-y-1">
            @if(!empty($menu->menuItems))
                @foreach ($menu->menuItems as $menuItem)
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

                    <li x-cloak
                        x-data="{ openMenuSidebar: {{ $menuItemActive ? 'true' : 'false' }}, isHovered: false, menuItemActive: {{$menuItemActive ? 'true' : 'false'}} }"
                        class="rounded-lg overflow-hidden">
                        @if ($menuItem->children->isEmpty())
                            <a href="{{ $menuUrl($menuItem) }}"
                               class="flex items-center w-full px-6 py-4 text-gray-700 transition-all duration-300 hover:bg-primary hover:text-borderGray hover:shadow-md hover:scale-[1.02] rounded-lg group {{ $menuItemActive ? 'bg-primary text-white shadow-lg' : 'hover:bg-gray-50' }}">
                                <span class="text-base font-medium">{{$menuItem->title}}</span>
                                <div class="ml-auto opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="fas fa-chevron-right text-sm"></i>
                                </div>
                            </a>
                        @else
                            <button
                                    @click="openMenuSidebar = !openMenuSidebar"
                                    @mouseover="isHovered = true"
                                    @mouseout="isHovered = false"
                                    :class="{ 'bg-primary text-white shadow-lg': openMenuSidebar || menuItemActive }"
                                    class="flex items-center w-full px-6 py-4 text-gray-700 hover:bg-primary hover:text-borderGray transition-all duration-300 hover:shadow-md hover:scale-[1.02] rounded-lg group {{ $menuItemActive ? 'text-white' : '' }}">
                                <span class="flex-1 text-start text-base font-medium">{{ $menuItem->title }}</span>
                                <svg :class="{'rotate-180': openMenuSidebar}"
                                     class="w-5 h-5 transition-transform duration-300 ml-2" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                          stroke-width="2" d="m1 1 4 4 4-4"/>
                                </svg>
                            </button>

                            <!-- Submenu với animation mượt -->
                            <ul
                                    x-show="openMenuSidebar"
                                    x-transition:enter="transition ease-out duration-300"
                                    x-transition:enter-start="opacity-0 transform -translate-y-2"
                                    x-transition:enter-end="opacity-100 transform translate-y-0"
                                    x-transition:leave="transition ease-in duration-200"
                                    x-transition:leave-start="opacity-100 transform translate-y-0"
                                    x-transition:leave-end="opacity-0 transform -translate-y-2"
                                    class="bg-gray-50/80 backdrop-blur-sm rounded-lg mt-2 mb-3 py-2 border border-gray-200 shadow-inner">
                                @foreach ($menuItem->children as $child)
                                    @php
                                        $childActive = $isActive($child);
                                    @endphp
                                    <li x-cloak
                                        x-data="{ openChildMenu: {{ $childActive ? 'true' : 'false' }}, childActive: {{ $childActive ? 'true' : 'false' }} }"
                                        class="mx-2 rounded-md overflow-hidden">
                                        @if ($child->children->isEmpty())
                                            <a href="{{ $menuUrl($child) }}"
                                               class="flex items-center w-full px-4 py-3 text-gray-600 transition-all duration-300 hover:bg-borderGray hover:text-primary rounded-md group relative {{ $childActive ? 'bg-primary text-white shadow-md' : 'hover:bg-white/70' }}">
                                                <div class="w-2 h-2 bg-current rounded-full mr-3 opacity-60"></div>
                                                <span class="text-sm font-medium">{{$child->title}}</span>
                                                <div class="ml-auto opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <i class="fas fa-chevron-right text-xs"></i>
                                                </div>
                                            </a>
                                        @else
                                            <button
                                                    @click="openChildMenu = !openChildMenu"
                                                    class="flex items-center w-full px-4 py-3 text-gray-600 transition-all duration-300 hover:bg-primary hover:text-borderGray rounded-md group {{ $childActive ? 'bg-primary text-white shadow-md' : 'hover:bg-white/70' }}">
                                                <div class="w-2 h-2 bg-current rounded-full mr-3 opacity-60"></div>
                                                <span class="flex-1 text-start text-sm font-medium">{{ $child->title }}</span>
                                                <svg :class="{'rotate-180': openChildMenu}"
                                                     class="w-4 h-4 transition-transform duration-300 ml-2" aria-hidden="true"
                                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                                          stroke-width="2" d="m1 1 4 4 4-4"/>
                                                </svg>
                                            </button>

                                            <!-- Third level menu -->
                                            <ul
                                                    x-show="openChildMenu"
                                                    x-transition:enter="transition ease-out duration-300"
                                                    x-transition:enter-start="opacity-0 transform -translate-y-2"
                                                    x-transition:enter-end="opacity-100 transform translate-y-0"
                                                    x-transition:leave="transition ease-in duration-200"
                                                    x-transition:leave-start="opacity-100 transform translate-y-0"
                                                    x-transition:leave-end="opacity-0 transform -translate-y-2"
                                                    class="bg-white/60 backdrop-blur-sm rounded-md mt-2 mb-3 py-2 mx-2 border border-gray-300 shadow-sm">
                                                @foreach ($child->children as $grandchild)
                                                    @php
                                                        $grandchildActive = $isActive($grandchild);
                                                    @endphp
                                                    <li class="mx-2">
                                                        <a href="{{ $menuUrl($grandchild) }}"
                                                           class="flex items-center w-full px-4 py-2 text-gray-600 transition-all duration-300 hover:bg-primary hover:text-borderGray rounded-md group {{ $grandchildActive ? 'bg-primary text-white shadow-md' : 'hover:bg-gray-100' }}">
                                                            <div class="w-1 h-1 bg-current rounded-full mr-3 opacity-60"></div>
                                                            <span class="text-sm">{{$grandchild->title}}</span>
                                                            <div class="ml-auto opacity-0 group-hover:opacity-100 transition-opacity">
                                                                <i class="fas fa-chevron-right text-xs"></i>
                                                            </div>
                                                        </a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            @endif
        </ul>
    </div>

    <div class="absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-white to-transparent pointer-events-none"></div>
</div>
