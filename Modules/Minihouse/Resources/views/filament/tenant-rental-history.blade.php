@php
    $statusLabels = [
        'active'    => 'Đang hiệu lực',
        'expired'   => 'Hết hạn',
        'cancelled' => 'Đã huỷ',
    ];
    $statusColors = [
        'active'    => 'success',
        'expired'   => 'gray',
        'cancelled' => 'danger',
    ];
@endphp

@if ($contracts->isEmpty())
    <div class="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
        Khách thuê này chưa có hợp đồng nào.
    </div>
@else
    <div class="space-y-2">
        @foreach ($contracts as $contract)
            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <div>
                    <div class="text-sm font-semibold text-gray-950 dark:text-white">
                        Phòng {{ $contract->room?->code ?? '—' }}
                    </div>
                    <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ $contract->start_date->format('d/m/Y') }} – {{ $contract->end_date?->format('d/m/Y') ?? 'nay' }}
                        &middot; {{ number_format((float) $contract->monthly_price, 0, ',', '.') }}đ/tháng
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$statusColors[$contract->status] ?? 'gray'">
                        {{ $statusLabels[$contract->status] ?? $contract->status }}
                    </x-filament::badge>

                    <x-filament::link
                        :href="\Modules\Minihouse\App\Filament\Resources\ContractResource::getUrl('edit', ['record' => $contract])"
                        icon="heroicon-o-eye"
                    >
                        Xem hợp đồng
                    </x-filament::link>
                </div>
            </div>
        @endforeach
    </div>
@endif
