{{-- "Dòng gọn" cho mobile (< md) — dùng Tables\Columns\ViewColumn (KHÔNG phải
Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ <table> cổ điển như cũ, không bật
Table::hasColumnsLayout() (cơ chế đó đổi HẲN cách toàn bộ bảng render, ảnh hưởng luôn cả desktop —
đã thử và gặp đúng lỗi này với Split/Stack, không lặp lại nữa). Cột này ->hiddenFrom('md') (xem
TenantFeedbackTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\TenantFeedback $feedback */
    $feedback = $getRecord();
    $name = $feedback->tenant_name ?: 'Ẩn danh';
    $initials = collect(explode(' ', trim($name)))->map(fn ($w) => mb_substr($w, 0, 1))->slice(-2)->implode('');
    $stars = str_repeat('★', $feedback->rating) . str_repeat('☆', 5 - $feedback->rating);
@endphp

<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ mb_strtoupper($initials) ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $name }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">{{ $feedback->room?->code ?? 'Chung' }} · {{ $stars }}</div>
        </div>

        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ $feedback->created_at?->format('d/m/Y') }}</div>
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-400 dark:text-gray-500">
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full {{ $feedback->is_reviewed ? 'bg-success-500' : 'bg-warning-500' }}"
                    title="{{ $feedback->is_reviewed ? 'Đã xử lý' : 'Chưa xử lý' }}"
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

    {{-- Nội dung góp ý (content) không hiện ở dòng gọn/cột desktop — chỉ xem được ở đây. --}}
    <div x-show="expanded" x-collapse x-cloak class="min-w-0 w-full rounded-lg bg-gray-50 p-2.5 text-[11.5px] dark:bg-white/5" style="margin-top: 4px;">
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Khách: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $name }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $feedback->room?->code ?? 'Chung' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Đánh giá: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $stars }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Góp ý: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $feedback->content ?: '—' }}</span></div>
    </div>
</div>
