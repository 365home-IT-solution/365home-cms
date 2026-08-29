<div class="fi-price-board-schedule rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <x-heroicon-o-calendar-days class="h-5 w-5 text-gray-400" />
            <span class="text-sm font-semibold text-gray-950 dark:text-white">Tổng quan lịch trình</span>
        </div>

        <span @class([
            'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
            'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $statusColor === 'success',
            'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400' => $statusColor === 'warning',
            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => $statusColor === 'gray',
            'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $statusColor === 'danger',
        ])>
            {{ $statusLabel }}
        </span>
    </div>

    <div class="mt-4 flex items-center gap-3">
        <div class="flex shrink-0 flex-col items-center">
            <div class="flex h-11 w-11 flex-col items-center justify-center rounded-lg bg-gray-100 leading-none dark:bg-white/10">
                <span class="text-[10px] font-medium uppercase text-gray-500 dark:text-gray-400">{{ $startMonthLabel }}</span>
                <span class="text-sm font-bold text-gray-950 dark:text-white">{{ $startDayLabel }}</span>
            </div>
        </div>

        <div class="min-w-0 shrink-0">
            <div class="text-[11px] uppercase tracking-wide text-gray-400">Bắt đầu</div>
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $startTimeLabel }}</div>
            <div class="text-xs text-gray-400">{{ $startDateFullLabel }}</div>
        </div>

        <div class="mx-1 flex min-w-0 flex-1 flex-col items-center gap-1">
            <span class="rounded-full bg-gray-100 px-3 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                {{ $durationLabel }}
            </span>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div class="h-full rounded-full bg-primary-500" style="width: {{ $progressPercent }}%"></div>
            </div>
        </div>

        <div class="min-w-0 shrink-0 text-right">
            <div class="text-[11px] uppercase tracking-wide text-gray-400">Kết thúc</div>
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $endTimeLabel }}</div>
            <div class="text-xs text-gray-400">{{ $endDateFullLabel }}</div>
        </div>

        <div class="flex shrink-0 flex-col items-center">
            <div class="flex h-11 w-11 flex-col items-center justify-center rounded-lg bg-gray-100 leading-none dark:bg-white/10">
                <span class="text-[10px] font-medium uppercase text-gray-500 dark:text-gray-400">{{ $endMonthLabel }}</span>
                <span class="text-sm font-bold text-gray-950 dark:text-white">{{ $endDayLabel }}</span>
            </div>
        </div>
    </div>
</div>
