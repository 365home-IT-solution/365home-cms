{{-- "Dòng gọn" cho mobile (< md) — dùng Tables\Columns\ViewColumn (KHÔNG phải
Tables\Columns\Layout\*) nên bảng vẫn ở đúng chế độ <table> cổ điển như cũ, không bật
Table::hasColumnsLayout() (cơ chế đó đổi HẲN cách toàn bộ bảng render, ảnh hưởng luôn cả desktop —
đã thử và gặp đúng lỗi này với Split/Stack, không lặp lại nữa). Cột này ->hiddenFrom('md') (xem
TransactionTable::table()), các cột gốc còn lại ->visibleFrom('md') — desktop không đổi 1 chữ nào. --}}
@php
    /** @var \Modules\Minihouse\App\Models\Transaction $transaction */
    $transaction = $getRecord();
    $isIn = $transaction->type === \Modules\Minihouse\App\Models\Transaction::TYPE_IN;
    $categoryLabel = match ($transaction->category) {
        \Modules\Minihouse\App\Models\Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
        \Modules\Minihouse\App\Models\Transaction::CATEGORY_OPERATION      => 'Vận hành',
        \Modules\Minihouse\App\Models\Transaction::CATEGORY_DEPOSIT_REFUND => 'Hoàn cọc',
        \Modules\Minihouse\App\Models\Transaction::CATEGORY_OTHER          => 'Khác',
        default => '—',
    };
@endphp

<div x-data="{ expanded: false }" class="min-w-0 w-full">
    <div class="flex min-w-0 w-full items-center gap-2.5 py-0.5">
        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-white/10 dark:text-gray-400">
            {{ $isIn ? 'Thu' : 'Chi' }}
        </div>

        <div class="min-w-0 flex-1">
            <div class="truncate text-[13px] font-semibold text-gray-950 dark:text-white">{{ $categoryLabel }}</div>
            <div class="truncate text-[11.5px] text-gray-400 dark:text-gray-500">{{ $transaction->building?->name ?? $transaction->contract?->room?->code ?? '—' }}</div>
        </div>

        <div class="flex-shrink-0 text-right">
            <div class="text-[12px] font-semibold {{ $isIn ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                {{ $isIn ? '+' : '-' }}{{ \Modules\Minihouse\App\Support\Money::format($transaction->amount) }}
            </div>
            <div class="flex items-center justify-end gap-1 text-[11px] text-gray-400 dark:text-gray-500">
                <span>{{ $transaction->transaction_date?->format('d/m/Y') ?? '—' }}</span>
                <span
                    class="inline-block h-1.5 w-1.5 rounded-full {{ $isIn ? 'bg-success-500' : 'bg-warning-500' }}"
                    title="{{ $isIn ? 'Thu' : 'Chi' }}"
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
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Loại: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $isIn ? 'Thu' : 'Chi' }} - {{ $categoryLabel }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Toà nhà / Phòng: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $transaction->building?->name ?? $transaction->contract?->room?->code ?? '—' }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Số tiền: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $isIn ? '+' : '-' }}{{ \Modules\Minihouse\App\Support\Money::format($transaction->amount) }}</span></div>
        <div style="margin-bottom: 5px;"><span class="text-gray-400 dark:text-gray-500">Ngày: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $transaction->transaction_date?->format('d/m/Y') ?? '—' }}</span></div>
        <div><span class="text-gray-400 dark:text-gray-500">Ghi chú: </span><span class="font-medium text-gray-700 dark:text-gray-300">{{ $transaction->note ?: '—' }}</span></div>
    </div>
</div>
