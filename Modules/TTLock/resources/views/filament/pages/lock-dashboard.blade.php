<x-filament-panels::page>
    <div class="mb-4 flex justify-end gap-3">
        <x-filament::button
            color="gray"
            icon="heroicon-o-key"
            tag="a"
            :href="route('filament.admin.pages.ttlock.issue-passcode')"
        >
            Cấp mã mở
        </x-filament::button>

        <x-filament::button
            color="gray"
            icon="heroicon-o-finger-print"
            tag="a"
            :href="route('filament.admin.pages.ttlock.issue-fingerprint')"
        >
            Cấp vân tay
        </x-filament::button>

        <x-filament::button
            icon="heroicon-o-identification"
            tag="a"
            :href="route('filament.admin.pages.ttlock.issue-card')"
        >
            Cấp thẻ từ
        </x-filament::button>
    </div>

    <div>
        <h3 class="text-base font-semibold mb-3">Danh sách khóa ({{ count($locks) }})</h3>

        @if (empty($locks))
            <p class="text-sm text-gray-500 dark:text-gray-400">Chưa có khóa nào — kiểm tra tài khoản TTLock đã cấu hình đúng chưa.</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Tên khóa</th>
                            <th class="px-3 py-2">Chi nhánh</th>
                            <th class="px-3 py-2">Nhóm</th>
                            <th class="px-3 py-2">MAC</th>
                            <th class="px-3 py-2">Pin</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($locks as $lock)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2 font-medium">{{ $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}" }}</td>
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $lock['branchName'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $lock['groupName'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $lock['lockMac'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @php $battery = $lock['electricQuantity'] ?? null; @endphp
                                    @if ($battery !== null)
                                        <span @class([
                                            'font-semibold',
                                            'text-danger-600 dark:text-danger-400' => $battery <= 20,
                                            'text-warning-600 dark:text-warning-400' => $battery > 20 && $battery <= 50,
                                            'text-success-600 dark:text-success-400' => $battery > 50,
                                        ])>{{ $battery }}%</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        tag="a"
                                        :href="route('filament.admin.pages.ttlock.locks.{categoryId}.{lockId}', ['categoryId' => $lock['categoryId'], 'lockId' => $lock['lockId']])"
                                    >
                                        Xem chi tiết
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
