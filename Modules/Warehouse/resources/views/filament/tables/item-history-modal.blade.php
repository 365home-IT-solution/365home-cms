{{-- Popup "Lịch sử biến động" mở từ danh sách Vật tư — cùng dữ liệu/cột với tab "Lịch sử biến
     động" ở trang Sửa vật tư (MovementsRelationManager), chỉ khác là xem NHANH ngay tại danh sách,
     không cần mở hẳn trang sửa. Bảng HTML thuần (không phải Filament\Tables\Table lồng trong modal —
     Filament không hỗ trợ nhúng 1 Table component đầy đủ vào ->modalContent() của Table Action một
     cách chính thức), style tay theo đúng ngôn ngữ hình ảnh admin (rounded-xl, ring-1 ring-gray-950/5,
     divide-y, dark: đầy đủ). --}}
<div class="overflow-x-auto">
    @if ($movements->isEmpty())
        <p class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Chưa có biến động nào.</p>
    @else
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pe-3 font-medium">Ngày chứng từ</th>
                    <th class="py-2 pe-3 font-medium">Loại</th>
                    <th class="py-2 pe-3 font-medium">Số phiếu</th>
                    <th class="py-2 pe-3 text-right font-medium">Biến động</th>
                    <th class="py-2 pe-3 text-right font-medium">Tồn sau</th>
                    <th class="py-2 pe-3 font-medium">Người thực hiện</th>
                    <th class="py-2 font-medium">Ghi chú</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($movements as $movement)
                    @php
                        $typeColor = match ($movement->type) {
                            'in'         => 'success',
                            'out'        => 'danger',
                            'check'      => 'warning',
                            default      => 'gray',
                        };
                        $isPositive = (float) $movement->quantity_change >= 0;
                    @endphp
                    <tr>
                        <td class="py-2 pe-3 whitespace-nowrap text-gray-700 dark:text-gray-300">
                            {{ $movement->occurred_at?->format('d/m/Y H:i') }}
                        </td>
                        <td class="py-2 pe-3">
                            <x-filament::badge :color="$typeColor">
                                {{ \Modules\Warehouse\App\Models\WarehouseStockMovement::TYPE_LABELS[$movement->type] ?? $movement->type }}
                            </x-filament::badge>
                        </td>
                        <td class="py-2 pe-3 font-mono text-gray-700 dark:text-gray-300">
                            {{ $movement->document_code ?? '—' }}
                        </td>
                        <td @class([
                            'py-2 pe-3 text-right font-semibold whitespace-nowrap',
                            'text-success-600 dark:text-success-400' => $isPositive,
                            'text-danger-600 dark:text-danger-400' => ! $isPositive,
                        ])>
                            {{ $isPositive ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $movement->quantity_change, 2, ',', '.'), '0'), ',') }}
                        </td>
                        <td class="py-2 pe-3 text-right text-gray-700 dark:text-gray-300">
                            {{ rtrim(rtrim(number_format((float) $movement->balance_after, 2, ',', '.'), '0'), ',') }}
                        </td>
                        <td class="py-2 pe-3 text-gray-700 dark:text-gray-300">
                            {{ \Modules\Warehouse\App\Filament\Support\CurrentUserDisplay::forUser($movement->creator) }}
                        </td>
                        <td class="py-2 text-gray-500 dark:text-gray-400">
                            {{ $movement->note ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
