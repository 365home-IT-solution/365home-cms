@php
    use Modules\Warehouse\App\Filament\Support\CurrentUserDisplay;
@endphp

{{-- Báo cáo lệch khi bàn giao ca — hiện rõ AI tạo phiếu gốc (ca trước), AI xác nhận bàn giao (ca
     sau), và bảng đối chiếu từng vật tư để người xem biết ngay lệch ở đâu, bao nhiêu, ai liên quan
     — xem EditWarehouseStockCheck::handoverReportAction(). --}}
<div class="space-y-4">
    <div class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Người tạo phiếu (ca trước)</div>
            <div class="font-medium text-gray-950 dark:text-white">{{ CurrentUserDisplay::forUser($record->creator) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Người xác nhận bàn giao (ca sau)</div>
            <div class="font-medium text-gray-950 dark:text-white">{{ CurrentUserDisplay::forUser($record->handoverConfirmedBy) }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Thời gian kiểm kê gốc</div>
            <div class="font-medium text-gray-950 dark:text-white">{{ optional($record->checked_at)->format('d/m/Y H:i') ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Thời gian xác nhận bàn giao</div>
            <div class="font-medium text-gray-950 dark:text-white">{{ optional($record->handover_confirmed_at)->format('d/m/Y H:i') ?? '—' }}</div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left text-xs text-gray-500 dark:border-gray-700 dark:bg-white/5 dark:text-gray-400">
                    <th class="px-3 py-2 font-medium">Vật tư</th>
                    <th class="px-3 py-2 text-right font-medium">Phiếu gốc</th>
                    <th class="px-3 py-2 text-right font-medium">Bàn giao ca sau</th>
                    <th class="px-3 py-2 text-right font-medium">Chênh lệch</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $line)
                    @php $diff = (float) ($line->handover_difference ?? 0); @endphp
                    <tr @class([
                        'border-b border-gray-100 dark:border-gray-800 last:border-0',
                        'bg-danger-50 dark:bg-danger-500/10' => $diff !== 0.0,
                    ])>
                        <td class="px-3 py-2 text-gray-950 dark:text-white">
                            {{ $line->item?->name }}
                            <span class="text-xs text-gray-400">({{ $line->item?->unit?->name ?? '—' }})</span>
                        </td>
                        <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format((float) $line->actual_quantity, 2, ',', '.'), '0'), ',') }}</td>
                        <td class="px-3 py-2 text-right">
                            {{ $line->handover_quantity !== null ? rtrim(rtrim(number_format((float) $line->handover_quantity, 2, ',', '.'), '0'), ',') : '—' }}
                        </td>
                        <td @class([
                            'px-3 py-2 text-right font-semibold',
                            'text-success-600 dark:text-success-400' => $diff > 0,
                            'text-danger-600 dark:text-danger-400' => $diff < 0,
                            'text-gray-400' => $diff === 0.0,
                        ])>
                            {{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 2, ',', '.'), '0'), ',') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($record->handover_note)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Ghi chú bàn giao</div>
            <div class="text-sm text-gray-950 dark:text-white">{{ $record->handover_note }}</div>
        </div>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Đây chỉ là báo cáo đối chiếu — tồn kho hệ thống KHÔNG bị thay đổi bởi bước bàn giao này. Nếu lệch là thật, hãy tạo 1 phiếu kiểm kê mới để điều chỉnh đúng tồn kho.
    </p>
</div>
