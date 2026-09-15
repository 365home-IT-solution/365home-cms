{{-- "Dòng gọn" cho mobile (< md) — dùng Tables\Columns\ViewColumn (KHÔNG phải
Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ <table> cổ điển như cũ, không bật
Table::hasColumnsLayout() (cơ chế đó đổi HẲN cách toàn bộ bảng render, ảnh hưởng luôn cả desktop —
đã thử và gặp đúng lỗi này với Split/Stack, không lặp lại nữa). Cột này ->hiddenFrom('md') (xem
SurchargeTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\Surcharge $surcharge */
    $surcharge = $getRecord();
    $initials = mb_strtoupper(mb_substr(trim($surcharge->name ?? ''), 0, 2));
@endphp

<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ $initials ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $surcharge->name }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">{{ $surcharge->building?->name ?? '—' }}</div>
        </div>

        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ \Modules\Minihouse\App\Support\Money::format($surcharge->amount) }}</div>
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-400 dark:text-gray-500">
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full {{ $surcharge->is_active ? 'bg-success-500' : 'bg-warning-500' }}"
                    title="{{ $surcharge->is_active ? 'Đang áp dụng' : 'Ngừng áp dụng' }}"
                ></span>
            </div>
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
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Tên phụ thu: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $surcharge->name }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Toà nhà: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $surcharge->building?->name ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Số tiền: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ \Modules\Minihouse\App\Support\Money::format($surcharge->amount) }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Trạng thái: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $surcharge->is_active ? 'Đang áp dụng' : 'Ngừng áp dụng' }}</span></div>
    </div>
</div>
