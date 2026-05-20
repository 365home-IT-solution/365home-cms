<div wire:key="app-preview">
    {{-- Phone shell --}}
    <div class="flex justify-center">
        <div class="w-72">

            {{-- Outer frame --}}
            <div class="relative rounded-[2.8rem] bg-gray-900 p-2.5 shadow-2xl ring-4 ring-gray-800">

                {{-- Dynamic island / notch --}}
                <div class="flex justify-center py-2">
                    <div class="h-5 w-28 rounded-full bg-black"></div>
                </div>

                {{-- Screen --}}
                <div class="overflow-hidden rounded-[2.2rem] bg-white" style="min-height: 580px;">

                    {{-- Status bar --}}
                    <div class="flex items-center justify-between bg-white px-5 pb-1 pt-1 text-[10px] font-medium text-gray-800">
                        <span>9:41</span>
                        <div class="flex items-center gap-1">
                            <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
                            <svg class="h-2.5 w-3" fill="currentColor" viewBox="0 0 24 24"><path d="M1.5 8.5a13 13 0 0121 0M5 12a10 10 0 0114 0M8.5 15.5a6 6 0 017 0M12 19h.01"/></svg>
                            <svg class="h-2.5 w-5" fill="currentColor" viewBox="0 0 24 24"><rect x="2" y="7" width="16" height="10" rx="2" ry="2"/><path d="M22 11v2a1 1 0 01-1 1h-1V10h1a1 1 0 011 1z"/></svg>
                        </div>
                    </div>

                    {{-- App bar --}}
                    <div class="border-b border-gray-100 bg-white px-4 py-2.5">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[11px] text-gray-400">Xin chào 👋</p>
                                <p class="text-xs font-bold text-gray-900">365 Home</p>
                            </div>
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100">
                                <svg class="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            </div>
                        </div>
                    </div>

                    {{-- Content area --}}
                    <div class="overflow-y-auto bg-gray-50" style="max-height: 480px;">

                        @forelse ($blocks as $block)
                            @php $type = $block['type'] ?? ''; $data = $block['data'] ?? []; @endphp

                            {{-- HEADING block --}}
                            @if ($type === 'heading')
                                <div class="px-4 pt-4 pb-1">
                                    <p class="text-sm font-bold text-gray-900 leading-tight">
                                        {{ $data['text'] ?? '' }}
                                    </p>
                                </div>

                            {{-- ROOM LIST block --}}
                            @elseif ($type === 'room_list')
                                <div class="px-4 pt-4 pb-2">

                                    {{-- Section header --}}
                                    <div class="mb-2 flex items-center justify-between">
                                        <div>
                                            <p class="text-xs font-semibold text-gray-900 leading-tight">
                                                {{ $data['title'] ?? 'Danh sách phòng' }}
                                            </p>
                                            @if (!empty($data['subtitle']))
                                                <p class="text-[10px] text-gray-400 mt-0.5">{{ $data['subtitle'] }}</p>
                                            @endif
                                        </div>
                                        @if ($data['show_arrow'] ?? true)
                                            <div class="flex items-center gap-1">
                                                <span class="text-[10px] text-blue-500">Xem thêm</span>
                                                <svg class="h-3 w-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Badge label (section-level) --}}
                                    @if (!empty($data['badge_label']))
                                        @php
                                            $bgColor   = $data['badge_bg_color']   ?? '#FEF3C7';
                                            $textColor = $data['badge_text_color'] ?? '#92400E';
                                        @endphp
                                        <div class="mb-2 inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-medium"
                                             style="background-color: {{ $bgColor }}; color: {{ $textColor }};">
                                            {{ $data['badge_label'] }}
                                        </div>
                                    @endif

                                    {{-- Fake room cards --}}
                                    @php $cardCount = min((int) ($data['limit'] ?? 3), 5); @endphp

                                    @if (($data['layout'] ?? 'horizontal_scroll') === 'grid')
                                        {{-- Grid layout --}}
                                        <div class="grid grid-cols-2 gap-2">
                                            @for ($i = 0; $i < min($cardCount, 4); $i++)
                                                <div class="rounded-xl overflow-hidden bg-white shadow-sm border border-gray-100">
                                                    <div class="h-16 bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center">
                                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                                                    </div>
                                                    <div class="p-1.5">
                                                        <div class="mb-1 h-2 w-full rounded bg-gray-200"></div>
                                                        <div class="h-2 w-1/2 rounded bg-blue-100"></div>
                                                    </div>
                                                </div>
                                            @endfor
                                        </div>
                                    @else
                                        {{-- Horizontal scroll layout --}}
                                        <div class="flex gap-2.5 overflow-x-auto pb-2 scrollbar-hide">
                                            @for ($i = 0; $i < $cardCount; $i++)
                                                <div class="flex-shrink-0 w-32 rounded-xl overflow-hidden bg-white shadow-sm border border-gray-100">
                                                    {{-- Thumbnail placeholder --}}
                                                    <div class="relative h-20 bg-gradient-to-br from-gray-200 to-gray-300">
                                                        <div class="absolute inset-0 flex items-center justify-center">
                                                            <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" points="9 22 9 12 15 12 15 22"/></svg>
                                                        </div>
                                                    </div>
                                                    {{-- Card info --}}
                                                    <div class="p-1.5">
                                                        <div class="mb-1 h-2 w-full rounded bg-gray-200"></div>
                                                        <div class="mb-1.5 h-1.5 w-3/4 rounded bg-gray-100"></div>
                                                        <div class="h-2 w-1/2 rounded bg-blue-100"></div>
                                                    </div>
                                                </div>
                                            @endfor
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @empty
                            <div class="flex flex-col items-center justify-center py-16 text-center">
                                <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100">
                                    <svg class="h-7 w-7 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                </div>
                                <p class="text-xs font-medium text-gray-400">Thêm block để xem preview</p>
                                <p class="mt-1 text-[10px] text-gray-300">Kéo thả các block ở bên trái</p>
                            </div>
                        @endforelse

                        <div class="h-6"></div>
                    </div>

                    {{-- Bottom nav bar --}}
                    <div class="border-t border-gray-100 bg-white px-4 py-2">
                        <div class="flex justify-around">
                            @foreach (['Trang chủ', 'Tìm kiếm', 'Đặt phòng', 'Hồ sơ'] as $nav)
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="h-4 w-4 rounded bg-gray-200"></div>
                                    <span class="text-[8px] text-gray-400">{{ $nav }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                </div>{{-- /screen --}}
            </div>{{-- /frame --}}

            {{-- Layout label --}}
            <p class="mt-3 text-center text-[10px] text-gray-400">
                {{ count($blocks) }} block{{ count($blocks) !== 1 ? 's' : '' }} • cập nhật khi bạn thêm/xóa block
            </p>

        </div>
    </div>
</div>
