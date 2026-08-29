<div class="fi-price-board-history">
    @if ($logs->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Chưa có thay đổi giá nào được ghi nhận.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4 font-medium">Thời gian</th>
                        <th class="py-2 pr-4 font-medium">Phòng</th>
                        <th class="py-2 pr-4 font-medium">Bảng giá</th>
                        <th class="py-2 pr-4 font-medium">Thay đổi</th>
                        <th class="py-2 pr-4 font-medium">Thực hiện bởi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $log->created_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap font-medium">
                                {{ $log->product?->name ?? '(phòng đã xoá)' }}
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $log->priceBoard?->name ?? '—' }}
                            </td>
                            <td class="py-2 pr-4">
                                {{ $log->summary() }}
                            </td>
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $log->changedByUser?->fullname ?? $log->changedByUser?->email ?? 'Tự động (theo lịch)' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
