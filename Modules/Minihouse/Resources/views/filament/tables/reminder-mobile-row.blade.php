{{-- "Dòng gọn" cho mobile (< md) — cùng cơ chế với tenant-mobile-row.blade.php (xem ghi chú ở đó):
dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ
<table> cổ điển, không bật Table::hasColumnsLayout(). Cột này ->hiddenFrom('md') (xem
ReminderTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\Reminder $reminder */
    $reminder = $getRecord();
    $typeLabel = match ($reminder->type) {
        \Modules\Minihouse\App\Models\Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
        \Modules\Minihouse\App\Models\Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
        \Modules\Minihouse\App\Models\Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
        default => 'Khác',
    };
@endphp

{{-- x-data riêng cho từng dòng; nút mở rộng dùng @click.stop.prevent — BẮT BUỘC vì Filament tự bọc
cột này trong <a href=".../edit">, không chặn lại sẽ vô tình điều hướng sang trang Sửa khi bấm nút. --}}
<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ mb_strtoupper(mb_substr($reminder->title ?? '', 0, 2)) ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $reminder->title }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">
                {{ $typeLabel }}
                @if ($reminder->room?->code)
                    &middot; {{ $reminder->room->code }}
                @endif
            </div>
        </div>

        {{-- CHỈ chấm màu + tooltip trên dòng gọn — bảng này chỉ còn 110px cho thẻ (4 nút hành
        động), chữ "Đã xử lý"/"Chưa xử lý" không có chỗ co giãn từng bị CẮT CỨNG khi hết chỗ ngang
        (phát hiện qua ảnh chụp thật). Chữ ĐẦY ĐỦ chuyển vào khối mở rộng, bấm chevron để xem. --}}
        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ $reminder->remind_date?->format('d/m/Y') ?? '—' }}</div>
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-400 dark:text-gray-500">
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full {{ $reminder->is_done ? 'bg-success-500' : 'bg-warning-500' }}"
                    title="{{ $reminder->is_done ? 'Đã xử lý' : 'Chưa xử lý' }}"
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

    {{-- Xếp DỌC (không dùng grid/flex nằm ngang) — tránh mọi class Tailwind ít dùng, đã xác nhận
    nhiều class dạng đó không có tác dụng ở panel này (chưa từng build vào CSS theme). --}}
    <div x-show="expanded" x-collapse x-cloak class="min-w-0 w-full rounded-lg bg-gray-50 p-2.5 text-[11.5px] dark:bg-white/5" style="margin-top: 4px;">
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Tiêu đề: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $reminder->title }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Loại: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $typeLabel }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $reminder->room?->code ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Ngày nhắc: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $reminder->remind_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Trạng thái: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $reminder->is_done ? 'Đã xử lý' : 'Chưa xử lý' }}</span></div>
    </div>
</div>
