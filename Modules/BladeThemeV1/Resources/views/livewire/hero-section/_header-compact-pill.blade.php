{{-- Pill gọn hiển thị trong header khi cuộn xuống (thế chỗ menu), phong cách Airbnb.
     Mỗi phần (Địa điểm/Thời gian/Khách) là 1 nút bấm riêng: bấm vào phần nào sẽ vừa mở rộng
     thanh tìm kiếm đầy đủ (searchExpanded) vừa mở luôn popup của đúng phần đó — không cần bấm
     2 lần. Bắn CustomEvent 'hero-open-field' trên window vì pill này bị x-teleport ra ngoài
     phạm vi Alpine scope của _banner-form nên không thể gọi thẳng locOpen/open/guestsOpen. --}}
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

<div
    x-show="isSticky && !searchExpanded"
    x-cloak
    x-transition:enter="transition ease-out duration-200 delay-100"
    x-transition:enter-start="opacity-0 scale-95"
    x-transition:enter-end="opacity-100 scale-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 scale-100"
    x-transition:leave-end="opacity-0 scale-95"
    class="flex w-full max-w-2xl mx-auto items-center overflow-hidden rounded-full border border-gray-200 bg-white shadow-[0_1px_6px_rgba(0,0,0,0.08)] transition-shadow duration-200 hover:border-gray-300 hover:shadow-[0_4px_14px_rgba(0,0,0,0.12)]">

    <button type="button"
        @click="searchExpanded = true; window.dispatchEvent(new CustomEvent('hero-open-field', { detail: 'loc' }))"
        class="flex min-w-0 flex-1 items-center gap-2 py-3 pl-5 pr-3 text-left hover:bg-gray-50 transition-colors">
        <svg class="h-4 w-4 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <span class="truncate text-base font-semibold text-gray-800">{{ $compactLocationLabel }}</span>
    </button>

    <span class="h-6 w-px shrink-0 bg-gray-200"></span>

    <button type="button"
        @click="searchExpanded = true; window.dispatchEvent(new CustomEvent('hero-open-field', { detail: 'date' }))"
        class="min-w-0 flex-1 truncate px-4 py-3 text-left text-base font-semibold text-gray-800 hover:bg-gray-50 transition-colors">
        {{ $compactDateLabel }}
    </button>

    <span class="h-6 w-px shrink-0 bg-gray-200"></span>

    <button type="button"
        @click="searchExpanded = true; window.dispatchEvent(new CustomEvent('hero-open-field', { detail: 'guests' }))"
        class="min-w-0 flex-1 truncate py-3 pl-4 pr-1 text-left text-base font-semibold text-gray-800 hover:bg-gray-50 transition-colors">
        {{ $compactGuestsLabel }}
    </button>

    <span class="shrink-0 py-1.5 pl-1.5 pr-2">
        <button type="button" @click="searchExpanded = true"
            class="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--color-primary)] hover:opacity-90 transition-opacity">
            <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>
    </span>
</div>
