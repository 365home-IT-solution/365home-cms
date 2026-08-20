@php
    /** @var \Illuminate\Support\Collection $branches */
    $selectedRoom = $selectedProductId
        ? $branches->flatMap(fn ($branch) => $branch->products)->firstWhere('id', $selectedProductId)
        : null;
@endphp

{{-- - z-index: topbar (sticky top-0 z-20) đè lên panel mặc định (z-10) khi panel lật lên phía
       trên (không đủ chỗ bên dưới trigger).
     - width: TRÊN DESKTOP (≥640px) panel mặc định LUÔN chiếm đủ max-width được cấp (class Tailwind
       chỉ giới hạn TRẦN, không co theo nội dung) — dù các chi nhánh chỉ cần vài trăm px, panel vẫn
       bị ép rộng y hệt mức trần, để lại 1 MẢNG TRẮNG RỖNG đè lên field bên dưới. width: max-content
       bắt panel tự co khít đúng nội dung, max-width vẫn chặn để không bao giờ tràn quá 95% màn
       hình. TRÊN MOBILE các chi nhánh xếp DỌC 1 cột duy nhất (full width) nên panel dùng width cố
       định theo % viewport thay vì max-content — max-content không tính đúng kích thước khi con
       bên trong có width theo %.
     KHÔNG dùng teleport (panel ở lại trong .fi-warehouse-room-picker thay vì bị đưa ra <body>) —
     để CSS này CHỈ áp dụng cho đúng dropdown của module Warehouse, không đụng vào mega menu top
     nav thật (cũng dùng chung class .fi-dropdown-panel, width:max-content sẽ phá layout grid nhiều
     cột của nó nếu áp dụng chung toàn cục). Section của Filament không set overflow:hidden nên
     panel vẫn hiện đầy đủ, không bị cắt. --}}
@once
    <style>
        .fi-warehouse-room-picker .fi-dropdown-panel {
            z-index: 40 !important;
            max-width: 95vw !important;
            width: 92vw !important;
        }

        @media (min-width: 640px) {
            .fi-warehouse-room-picker .fi-dropdown-panel {
                width: max-content !important;
            }
        }
    </style>
@endonce

<div class="fi-warehouse-room-picker">
    {{-- ViewField dùng ->view() tùy biến sẽ THAY THẾ toàn bộ output, không tự có label như field
         thường (Filament chỉ tự render label qua field-wrapper mà field thường mới include) — tự
         vẽ label ở đây cho đúng style chuẩn. --}}
    @if ($label ?? null)
        <label class="fi-fo-field-wrp-label mb-2 inline-flex items-center gap-x-3">
            <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">{{ $label }}</span>
        </label>
    @endif

    @if ($branches->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Bạn không có quyền truy cập chi nhánh nào để chọn phòng.</p>
    @else
        {{-- Dropdown thu gọn, đúng component đang dùng cho mega menu top nav (xem
             resources/views/vendor/filament-panels/components/topbar/index.blade.php) — chỉ xổ
             lưới chi nhánh × phòng ra khi bấm vào, không hiển thị sẵn chiếm chỗ trên form. Desktop:
             các chi nhánh nằm trên 1 HÀNG NGANG duy nhất (không tự xuống hàng), không bao giờ
             chồng/che lên field khác bên dưới. Mobile: xếp DỌC 1 cột (xem chi tiết ở khối bên
             dưới). --}}
        <x-filament::dropdown placement="bottom-start">
            <x-slot name="trigger">
                <button
                    type="button"
                    class="fi-input flex w-full items-center justify-between rounded-lg border px-3 py-2 text-sm shadow-sm
                        border-gray-300 dark:border-gray-600 dark:bg-white/5"
                >
                    <span @class([$selectedRoom ? 'font-medium' : 'text-gray-400'])>
                        {{ $selectedRoom?->name ?? 'Bấm để chọn phòng nhận hàng...' }}
                    </span>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4 text-gray-400" />
                </button>
            </x-slot>

            <div x-data="{ search: '' }" class="w-full sm:w-max">
                <div class="border-b border-gray-100 p-2 dark:border-gray-700">
                    <input
                        type="text"
                        x-model="search"
                        placeholder="Tìm phòng..."
                        class="fi-input block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-white/5"
                        x-on:click.stop
                    />
                </div>

                {{-- Mobile (< sm): các chi nhánh XẾP DỌC 1 cột duy nhất, full chiều rộng, cuộn DỌC
                     chung 1 lần (max-h + overflow ở container ngoài) — không sinh thanh cuộn ngang.
                     Desktop (≥ sm): giữ nguyên hàng ngang nhiều cột, mỗi cột tự cuộn dọc riêng. --}}
                <div class="flex max-h-[60vh] flex-col gap-2 overflow-y-auto p-3 sm:max-h-none sm:flex-row sm:flex-nowrap sm:overflow-visible">
                    @foreach ($branches as $branch)
                        <div class="w-full shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 sm:w-44">
                            <x-filament::dropdown.header class="rounded-t-lg bg-gray-50 py-1.5 font-semibold dark:bg-white/5">{{ $branch->name }}</x-filament::dropdown.header>

                            <div class="divide-y divide-gray-100 dark:divide-gray-700 sm:max-h-72 sm:overflow-y-auto">
                                @forelse ($branch->products as $room)
                                    @php $isSelected = $selectedProductId === $room->id; @endphp
                                    <button
                                        type="button"
                                        x-show="search === '' || '{{ \Illuminate\Support\Str::lower($room->name) }}'.includes(search.toLowerCase())"
                                        wire:click="selectStockOutRoom('{{ $room->id }}')"
                                        x-on:click="close()"
                                        wire:key="warehouse-room-{{ $room->id }}"
                                        class="block w-full px-3 py-2 text-left text-sm transition-colors
                                            {{ $isSelected
                                                ? 'bg-primary-600 text-white'
                                                : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' }}"
                                    >
                                        {{ $room->name }}
                                    </button>
                                @empty
                                    <p class="px-3 py-2 text-xs text-gray-400">Chi nhánh chưa có phòng.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-filament::dropdown>

        @if ($selectedRoom)
            <p class="mt-1.5 text-xs">
                <button type="button" wire:click="selectStockOutRoom('{{ $selectedProductId }}')" class="text-danger-600 hover:underline">Bỏ chọn phòng</button>
            </p>
        @endif
    @endif
</div>
