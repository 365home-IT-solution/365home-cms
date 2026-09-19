<x-filament-panels::page>
    <div class="mb-4">
        <a href="{{ route('filament.admin.pages.ttlock.dashboard') }}" class="text-sm text-primary-600 hover:underline dark:text-primary-400">
            ← Quay lại danh sách khóa
        </a>
    </div>

    @if (! $lock)
        <p class="text-sm text-danger-600 dark:text-danger-400">Không tìm thấy khóa này — kiểm tra lại tài khoản TTLock hoặc khóa đã bị xoá.</p>
    @else
        <div class="mb-6 flex flex-wrap items-center gap-x-8 gap-y-1 text-sm">
            <span><span class="text-gray-500 dark:text-gray-400">MAC:</span> <span class="font-mono">{{ $lock['lockMac'] ?? '—' }}</span></span>
            <span><span class="text-gray-500 dark:text-gray-400">Nhóm:</span> {{ $lock['groupName'] ?? '—' }}</span>
            <span><span class="text-gray-500 dark:text-gray-400">Pin:</span> {{ $lock['electricQuantity'] ?? '—' }}%</span>
        </div>

        @php
            $tabs = [
                'cards'        => ['label' => 'Thẻ từ', 'count' => count($cards)],
                'passcodes'    => ['label' => 'Mã mở', 'count' => count($passcodes)],
                'fingerprints' => ['label' => 'Vân tay', 'count' => count($fingerprints)],
                'members'      => ['label' => 'Thành viên', 'count' => count($members)],
                'records'      => ['label' => 'Lịch sử mở khóa', 'count' => null],
            ];
        @endphp

        <div class="border-b border-gray-200 dark:border-white/10 mb-4">
            <nav class="flex flex-wrap gap-x-6 -mb-px">
                @foreach ($tabs as $key => $tab)
                    <button
                        type="button"
                        wire:click="$set('activeTab', '{{ $key }}')"
                        @class([
                            'py-2.5 text-sm font-medium border-b-2 whitespace-nowrap',
                            'border-primary-600 text-primary-600 dark:text-primary-400' => $activeTab === $key,
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activeTab !== $key,
                        ])
                    >
                        {{ $tab['label'] }}
                        @if ($tab['count'] !== null)
                            <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">({{ $tab['count'] }})</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        @if ($activeTab !== 'records')
            <div class="mb-4 max-w-sm">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Tìm kiếm theo tên hoặc mã..."
                    />
                </x-filament::input.wrapper>
            </div>
        @endif

        {{-- Thẻ từ --}}
        @if ($activeTab === 'cards')
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Tên</th>
                            <th class="px-3 py-2">Số thẻ</th>
                            <th class="px-3 py-2">Hết hạn</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->filteredCards as $card)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ $card['cardName'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono">{{ $card['cardNumber'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ($card['endDate'] ?? 0) > 0 ? \Modules\TTLock\App\Filament\Pages\LockDetail::msToDate((int) $card['endDate']) : 'Vĩnh viễn' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="deleteCard({{ $card['cardId'] }})"
                                        wire:confirm="Xoá thẻ này khỏi khóa?"
                                        class="text-danger-600 hover:underline dark:text-danger-400"
                                    >Xoá</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">Không có thẻ nào khớp</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $this->filteredCards->links() }}</div>
        @endif

        {{-- Mã mở --}}
        @if ($activeTab === 'passcodes')
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Tên</th>
                            <th class="px-3 py-2">Mã</th>
                            <th class="px-3 py-2">Hết hạn</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->filteredPasscodes as $pwd)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ $pwd['keyboardPwdName'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono">{{ $pwd['keyboardPwd'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ($pwd['endDate'] ?? 0) > 0 ? \Modules\TTLock\App\Filament\Pages\LockDetail::msToDate((int) $pwd['endDate']) : 'Vĩnh viễn' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="deletePasscode({{ $pwd['keyboardPwdId'] }})"
                                        wire:confirm="Xoá mã mở này khỏi khóa?"
                                        class="text-danger-600 hover:underline dark:text-danger-400"
                                    >Xoá</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">Không có mã nào khớp</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $this->filteredPasscodes->links() }}</div>
        @endif

        {{-- Vân tay --}}
        @if ($activeTab === 'fingerprints')
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Tên</th>
                            <th class="px-3 py-2">Hết hạn</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->filteredFingerprints as $fp)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ $fp['fingerprintName'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ($fp['endDate'] ?? 0) > 0 ? \Modules\TTLock\App\Filament\Pages\LockDetail::msToDate((int) $fp['endDate']) : 'Vĩnh viễn' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="deleteFingerprint({{ $fp['fingerprintId'] }})"
                                        wire:confirm="Xoá vân tay này khỏi khóa?"
                                        class="text-danger-600 hover:underline dark:text-danger-400"
                                    >Xoá</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400">Không có vân tay nào khớp</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $this->filteredFingerprints->links() }}</div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Vân tay mới chỉ đọc được qua app/thiết bị SDK riêng — trang này chỉ xem/xoá/quản lý, giống thẻ từ.
            </p>
        @endif

        {{-- Thành viên (ekey) --}}
        @if ($activeTab === 'members')
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Tài khoản</th>
                            <th class="px-3 py-2">Loại</th>
                            <th class="px-3 py-2">Hết hạn</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->filteredMembers as $m)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ $m['keyName'] ?? $m['remarks'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ($m['userType'] ?? '') === '110301' ? 'Quản trị' : 'Thành viên' }}</td>
                                <td class="px-3 py-2">{{ ($m['endDate'] ?? 0) > 0 ? \Modules\TTLock\App\Filament\Pages\LockDetail::msToDate((int) $m['endDate']) : 'Vĩnh viễn' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400">Không có thành viên nào khớp</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $this->filteredMembers->links() }}</div>
        @endif

        {{-- Lịch sử mở khóa --}}
        @if ($activeTab === 'records')
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left">
                            <th class="px-3 py-2">Thời gian</th>
                            <th class="px-3 py-2">Loại mở</th>
                            <th class="px-3 py-2">Người/mã dùng</th>
                            <th class="px-3 py-2">Thành công</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $rec)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-3 py-2">{{ \Modules\TTLock\App\Filament\Pages\LockDetail::msToDate((int) ($rec['lockDate'] ?? 0)) }}</td>
                                <td class="px-3 py-2">{{ $rec['recordTypeName'] ?? ($rec['recordType'] ?? '—') }}</td>
                                <td class="px-3 py-2">{{ $rec['username'] ?? $rec['keyboardPwd'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ ($rec['success'] ?? 1) == 1 ? 'Có' : 'Không' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400">Chưa có lịch sử nào</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <x-filament::button
                    size="sm"
                    color="gray"
                    :disabled="$recordsPageNo <= 1"
                    wire:click="goToRecordsPage({{ $recordsPageNo - 1 }})"
                >Trang trước</x-filament::button>
                <span class="text-sm text-gray-500 dark:text-gray-400">Trang {{ $recordsPageNo }}</span>
                <x-filament::button
                    size="sm"
                    color="gray"
                    :disabled="! $recordsHasMore"
                    wire:click="goToRecordsPage({{ $recordsPageNo + 1 }})"
                >Trang sau</x-filament::button>
            </div>
        @endif
    @endif
</x-filament-panels::page>
