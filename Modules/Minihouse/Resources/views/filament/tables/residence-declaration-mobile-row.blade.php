{{-- "Dòng gọn" cho mobile (< md) — cùng cơ chế với tenant-mobile-row.blade.php (xem ghi chú ở đó):
dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ
<table> cổ điển, không bật Table::hasColumnsLayout(). Cột này ->hiddenFrom('md') (xem
ResidenceDeclarationTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1
chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\ResidenceDeclaration $declaration */
    $declaration = $getRecord();
    $initials = collect(explode(' ', trim($declaration->full_name ?? '')))->map(fn ($w) => mb_substr($w, 0, 1))->slice(-2)->implode('');

    if ($declaration->isDeclared()) {
        $statusColor = 'bg-success-500';
        $statusLabel = 'Đã khai báo';
    } elseif (! $declaration->isDataComplete()) {
        $statusColor = 'bg-danger-500';
        $statusLabel = 'Thiếu thông tin';
    } elseif ($declaration->isOverdue()) {
        $statusColor = 'bg-danger-500';
        $statusLabel = 'Quá hạn';
    } elseif ($declaration->isDueSoon()) {
        $statusColor = 'bg-warning-500';
        $statusLabel = 'Sắp tới hạn';
    } else {
        $statusColor = 'bg-gray-400';
        $statusLabel = 'Chưa khai báo';
    }
@endphp

{{-- x-data riêng cho từng dòng; nút mở rộng dùng @click.stop.prevent để không vô tình điều hướng
sang trang Sửa nếu Filament có bọc cột này trong <a>/<button> (giống các bảng khác trong module). --}}
<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ mb_strtoupper($initials) ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $declaration->full_name ?: '—' }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">
                {{ $declaration->contract?->room?->code ?? '—' }}
                @if ($declaration->cccd_number)
                    &middot; {{ $declaration->cccd_number }}
                @endif
            </div>
        </div>

        {{-- CHỈ chấm màu + tooltip trên dòng gọn — chữ dài ("Sắp tới hạn", "Thiếu thông tin"...)
        không có chỗ co giãn từng bị cắt CỨNG (VD "Qu" thay vì "Quá hạn") khi hết chỗ ngang — phát
        hiện qua ảnh chụp thật. Chữ ĐẦY ĐỦ chuyển vào khối mở rộng, bấm chevron để xem. --}}
        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ $declaration->checked_in_at?->format('d/m/Y') ?? '—' }}</div>
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-400 dark:text-gray-500">
                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $statusColor }}" title="{{ $statusLabel }}"></span>
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
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Họ tên: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $declaration->full_name ?: '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $declaration->contract?->room?->code ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">CCCD: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $declaration->cccd_number ?: '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Ngày đến: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $declaration->checked_in_at?->format('d/m/Y') ?? '—' }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Trạng thái: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $statusLabel }}</span></div>
    </div>
</div>
