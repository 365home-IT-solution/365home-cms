{{-- Pill gọn hiển thị trong header mobile, luôn hiện (không phụ thuộc scroll).
     Giao diện đồng bộ với bản desktop (_header-compact-pill.blade.php): 3 ô riêng
     biệt ngăn cách bởi vạch dọc, thay vì gộp chung 1 dòng bằng dấu "·". --}}
@php
    $compactLocationLabelM = $selectedLocation
        ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Địa điểm bất kỳ')
        : 'Địa điểm bất kỳ';
    $compactDateLabelM = $checkIn
        ? ($checkIn . ($checkOut ? ' → ' . $checkOut : ''))
        : 'Thời gian bất kỳ';
    $compactGuestsLabelM = match ($selectedGuests) {
        '1', '2', '3', '4' => $selectedGuests . ' khách',
        '5' => '5+ khách',
        default => 'Thêm khách',
    };
@endphp

<button type="button"
    @click="mobileSearchOpen = true"
    class="flex w-full items-center overflow-hidden rounded-full border border-gray-200 bg-white shadow-[0_1px_6px_rgba(0,0,0,0.08)] transition-shadow duration-200 active:shadow-[0_1px_3px_rgba(0,0,0,0.1)]">

    <span class="flex min-w-0 flex-1 items-center gap-2 py-2 pl-4 pr-2">
        <span class="truncate text-sm font-semibold text-gray-800">{{ $compactLocationLabelM }}</span>
    </span>

    <span class="h-5 w-px shrink-0 bg-gray-200"></span>

    <span class="min-w-0 flex-1 truncate px-2 py-2 text-sm font-semibold text-gray-800">{{ $compactDateLabelM }}</span>

    <span class="h-5 w-px shrink-0 bg-gray-200"></span>

    <span class="min-w-0 flex-1 truncate py-2 pl-2 pr-1 text-sm font-semibold text-gray-800">{{ $compactGuestsLabelM }}</span>

    <span class="shrink-0 py-1 pl-1 pr-1.5">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--color-primary)]">
            <svg class="h-3.5 w-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </span>
    </span>
</button>
