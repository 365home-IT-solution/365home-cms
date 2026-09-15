{{-- "Dòng gọn" cho mobile (< md) — xem ghi chú đầy đủ ở tenant-mobile-row.blade.php. Dùng
Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ <table>
cổ điển như cũ, không bật Table::hasColumnsLayout(). Cột này ->hiddenFrom('md') (xem
BuildingTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\Building $building */
    $building = $getRecord();
@endphp

<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            @if ($building->image)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($building->image) }}" class="h-full w-full object-cover" alt="">
            @else
                <span class="text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ mb_strtoupper(mb_substr($building->name ?? '?', 0, 1)) }}</span>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $building->name }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">{{ $building->address ?: '—' }}</div>
        </div>

        <div class="flex-shrink-0 text-right">
            <div class="truncate text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ $building->zone?->name ?? '—' }}</div>
            <div class="text-[11px] text-gray-400 dark:text-gray-500">{{ $building->rooms_count ?? 0 }} phòng</div>
        </div>

        <button
            type="button"
            @click.stop.prevent="expanded = !expanded"
            class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10"
        >
            <svg :class="expanded ? 'rotate-180' : ''" class="h-3.5 w-3.5 transition-transform" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>
    </div>

    <div x-show="expanded" x-collapse x-cloak class="min-w-0 w-full rounded-lg bg-gray-50 p-2.5 text-[11.5px] dark:bg-white/5" style="margin-top: 4px;">
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Tên toà nhà: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $building->name }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Địa chỉ: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $building->address ?: '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Khu vực: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $building->zone?->name ?? '—' }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Số phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $building->rooms_count ?? 0 }}</span></div>
    </div>
</div>
