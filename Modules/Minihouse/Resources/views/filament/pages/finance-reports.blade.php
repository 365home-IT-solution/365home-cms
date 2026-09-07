<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Bộ lọc</x-slot>

        {{ $this->filtersForm }}
    </x-filament::section>

    @php
        $stats = $this->stats();
        $revenueByBuilding = $this->revenueByBuilding();
        $tenantDebts = $this->tenantDebts();
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $statCards = [
                ['label' => 'Đã thu tháng này', 'value' => number_format($stats['collected'], 0, ',', '.') . 'đ', 'color' => 'text-success-600 dark:text-success-400'],
                ['label' => 'Chưa thu tháng này', 'value' => number_format($stats['uncollected'], 0, ',', '.') . 'đ', 'color' => 'text-danger-600 dark:text-danger-400'],
                ['label' => 'Chi phí tháng này', 'value' => number_format($stats['expense'], 0, ',', '.') . 'đ', 'color' => 'text-gray-950 dark:text-white'],
                ['label' => 'Lợi nhuận (đã thu − chi)', 'value' => number_format($stats['profit'], 0, ',', '.') . 'đ', 'color' => $stats['profit'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400'],
            ];
        @endphp

        @foreach ($statCards as $card)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                <div class="mt-1 text-xl font-semibold {{ $card['color'] }}">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Tỷ lệ lấp đầy</x-slot>

        <div class="flex items-center gap-4">
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $stats['occupancy_rate'] }}%</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ $stats['rented_rooms'] }} / {{ $stats['total_rooms'] }} phòng đang thuê
            </div>
        </div>
    </x-filament::section>

    @if (! empty($revenueByBuilding))
        <x-filament::section>
            <x-slot name="heading">Doanh thu theo toà nhà (tháng này)</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Toà nhà</th>
                            <th class="py-2 pr-4 font-medium">Số hoá đơn</th>
                            <th class="py-2 pr-4 font-medium">Đã thu</th>
                            <th class="py-2 font-medium">Chưa thu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($revenueByBuilding as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $row['building'] }}</td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $row['count'] }}</td>
                                <td class="py-2 pr-4 text-success-600 dark:text-success-400">{{ number_format($row['collected'], 0, ',', '.') }}đ</td>
                                <td class="py-2 text-danger-600 dark:text-danger-400">{{ number_format($row['uncollected'], 0, ',', '.') }}đ</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Công nợ khách thuê hiện tại</x-slot>
        <x-slot name="description">Toàn bộ hoá đơn chưa thanh toán, không chỉ trong tháng đang lọc — nợ cũ vẫn tính là nợ.</x-slot>

        @if (empty($tenantDebts))
            <p class="text-sm text-gray-500 dark:text-gray-400">Không có khách thuê nào đang nợ.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Khách thuê</th>
                            <th class="py-2 pr-4 font-medium">Phòng</th>
                            <th class="py-2 pr-4 font-medium">Số hoá đơn nợ</th>
                            <th class="py-2 pr-4 font-medium">Nợ từ tháng</th>
                            <th class="py-2 font-medium">Tổng nợ</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tenantDebts as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $row['tenant'] }}</td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $row['room'] }}</td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $row['invoice_count'] }}</td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($row['oldest_month'])->format('m/Y') }}</td>
                                <td class="py-2 font-medium text-danger-600 dark:text-danger-400">{{ number_format($row['total_debt'], 0, ',', '.') }}đ</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
