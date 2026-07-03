{{-- Pill gọn hiển thị trong header khi cuộn xuống (thế chỗ menu), phong cách Airbnb --}}
@php
    $compactLocationLabel = $selectedLocation
        ? (collect($locations)->firstWhere('slug', $selectedLocation)['name'] ?? 'Địa điểm bất kỳ')
        : 'Địa điểm bất kỳ';
    $compactDateLabel = $checkIn
        ? ($checkIn . ($checkOut ? ' → ' . $checkOut : ''))
        : 'Thời gian bất kỳ';
    $compactGuestsLabel = match ($selectedGuests) {
        '1', '2', '3', '4' => $selectedGuests . ' khách',
        '5' => '5+ khách',
        default => 'Thêm khách',
    };
@endphp

<button type="button"
    x-show="isSticky"
    x-cloak
    @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
    x-transition:enter="transition ease-out duration-200 delay-100"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95"
    class="flex w-full max-w-xl mx-auto items-center overflow-hidden rounded-full border border-gray-200 bg-white shadow-[0_1px_6px_rgba(0,0,0,0.08)] transition-shadow duration-200 hover:border-gray-300 hover:shadow-[0_4px_14px_rgba(0,0,0,0.12)]">

    <span class="flex min-w-0 flex-1 items-center gap-2 py-2 pl-4 pr-3">
        <svg class="h-4 w-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <span class="truncate text-sm font-semibold text-gray-800">{{ $compactLocationLabel }}</span>
    </span>

    <span class="h-5 w-px shrink-0 bg-gray-200"></span>

    <span class="min-w-0 flex-1 truncate px-3 py-2 text-sm font-semibold text-gray-800">{{ $compactDateLabel }}</span>

    <span class="h-5 w-px shrink-0 bg-gray-200"></span>

    <span class="min-w-0 flex-1 truncate py-2 pl-3 pr-1 text-sm font-semibold text-gray-800">{{ $compactGuestsLabel }}</span>

    <span class="shrink-0 py-1 pl-1 pr-1.5">
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--color-primary)]">
            <svg class="h-3.5 w-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </span>
    </span>
</button>
