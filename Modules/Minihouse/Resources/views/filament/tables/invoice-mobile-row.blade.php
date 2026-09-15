{{-- "Dòng gọn" cho mobile (< md) — cùng cơ chế với tenant-mobile-row.blade.php (xem ghi chú ở đó):
dùng Tables\Columns\ViewColumn (KHÔNG phải Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ
<table> cổ điển, không bật Table::hasColumnsLayout(). Cột này ->hiddenFrom('md') (xem
InvoiceTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\Invoice $invoice */
    $invoice = $getRecord();
    $tenantName = $invoice->contract?->tenant?->fullname ?? '';
    $initials = collect(explode(' ', trim($tenantName)))->map(fn ($w) => mb_substr($w, 0, 1))->slice(-2)->implode('');
    $statusColor = match ($invoice->status) {
        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID    => 'bg-success-500',
        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'bg-info-500',
        default => 'bg-warning-500',
    };
    $statusLabel = match ($invoice->status) {
        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID    => 'Đã thanh toán',
        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
        default => 'Chưa thanh toán',
    };
@endphp

{{-- x-data riêng cho từng dòng; nút mở rộng dùng @click.stop.prevent — BẮT BUỘC vì Filament tự bọc
cột này trong <a href=".../edit">, không chặn lại sẽ vô tình điều hướng sang trang Sửa khi bấm nút. --}}
<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ mb_strtoupper($initials) ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $tenantName ?: '—' }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">
                {{ $invoice->contract?->room?->code ?? '—' }}
                @if ($invoice->month)
                    &middot; Tháng {{ $invoice->month->format('m/Y') }}
                @endif
            </div>
        </div>

        {{-- CHỈ chấm màu + tooltip trên dòng gọn — chữ dài ("Thanh toán 1 phần"...) không có chỗ co
        giãn (flex-shrink-0) từng bị CẮT CỨNG khi hết chỗ ngang (phát hiện qua ảnh chụp thật). Chữ
        ĐẦY ĐỦ chuyển vào khối mở rộng bên dưới, bấm chevron để xem. --}}
        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold text-gray-700 dark:text-gray-300">{{ \Modules\Minihouse\App\Support\Money::format($invoice->total_amount) }}</div>
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
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Khách thuê: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $tenantName ?: '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $invoice->contract?->room?->code ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Tháng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $invoice->month?->format('m/Y') ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Tổng tiền: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ \Modules\Minihouse\App\Support\Money::format($invoice->total_amount) }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Đã trả: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ \Modules\Minihouse\App\Support\Money::format($invoice->amount_paid) }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Trạng thái: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $statusLabel }}</span></div>
    </div>
</div>
