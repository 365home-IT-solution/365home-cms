        {{-- Pill thu gọn --}}
        <div x-show="!formOpen"
             class="w-full bg-white border-b border-gray-100 shadow-[0_2px_12px_rgba(0,0,0,0.07)]">

            <div class="flex items-center gap-2 md:gap-4 w-full md:px-8 px-4 mx-auto py-[9px]">

                {{-- Logo — về trang chủ --}}
                <a href="{{ url('/') }}"
                   class="flex items-center flex-shrink-0">
                    <img src="{{ asset('/storage/'.$logo) }}" alt="Logo"
                         style="height: {{ $logoHeight }}px;"
                         class="max-w-[110px] md:max-w-[160px] object-contain">
                </a>

                <button @click="formOpen = true"
                        class="flex-1 flex items-center gap-0 min-w-0 mx-auto max-w-[52rem] overflow-hidden cursor-pointer rounded-full border-[1.5px] border-gray-200 bg-white shadow-[0_1px_6px_rgba(0,0,0,0.08)] transition-shadow duration-200 hover:border-gray-300 hover:shadow-[0_4px_14px_rgba(0,0,0,0.12)]">

                    <span class="flex flex-1 flex-col items-start min-w-0 px-2 md:px-4 py-[9px]">
                        <span class="w-full overflow-hidden text-ellipsis whitespace-nowrap text-[9px] font-bold uppercase leading-none tracking-wide text-gray-500">Địa điểm</span>
                        <span class="mt-0.5 max-w-full overflow-hidden text-ellipsis whitespace-nowrap text-xs font-semibold text-gray-900">
                            {{ $selectedLocation ? collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Chọn địa điểm' : 'Tìm kiếm địa điểm' }}
                        </span>
                    </span>

                    <span class="h-7 w-px flex-shrink-0 bg-gray-200"></span>

                    <span class="flex flex-1 flex-col items-start min-w-0 px-2 md:px-4 py-[9px]">
                        <span class="w-full overflow-hidden text-ellipsis whitespace-nowrap text-[9px] font-bold uppercase leading-none tracking-wide text-gray-500">Thời gian</span>
                        <span class="mt-0.5 overflow-hidden text-ellipsis whitespace-nowrap text-xs font-semibold text-gray-900">
                            {{ $checkIn ? $checkIn . ($checkOut ? ' → ' . $checkOut : '') : 'Thêm ngày' }}
                        </span>
                    </span>

                    <span class="h-7 w-px flex-shrink-0 bg-gray-200"></span>

                    <span class="flex flex-1 flex-col items-start min-w-0 px-2 md:px-4 py-[9px]">
                        <span class="w-full overflow-hidden text-ellipsis whitespace-nowrap text-[9px] font-bold uppercase leading-none tracking-wide text-gray-500">Loại đặt</span>
                        <span class="mt-0.5 overflow-hidden text-ellipsis whitespace-nowrap text-xs font-semibold text-gray-900">
                            @if ($selectedBuoi === '1') Theo giờ
                            @elseif ($selectedBuoi === '2') Theo ngày
                            @else Tất cả @endif
                        </span>
                    </span>

                    <span class="flex-shrink-0 py-[5px] pl-0 pr-2">
                        <span class="flex h-[34px] w-[34px] items-center justify-center rounded-full bg-teal-700">
                            <svg class="h-[15px] w-[15px] text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </span>
                    </span>
                </button>

                {{-- Hamburger --}}
                <button @click.stop="menuOpen = !menuOpen"
                        class="flex h-[42px] w-[42px] flex-shrink-0 items-center justify-center rounded-xl border-[1.5px] border-gray-200 bg-white shadow-[0_1px_6px_rgba(0,0,0,0.08)] transition-all duration-200 hover:border-teal-200 hover:bg-teal-50">
                    <svg class="h-[18px] w-[18px] text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
