<div>
    {{-- ===== NÚT MỞ MODAL (hiện trong header page) ===== --}}
    <x-filament::button wire:click="openModal" color="danger" icon="heroicon-o-no-symbol" size="sm">
        Tô đen / Khóa lịch
    </x-filament::button>

    {{-- ===== MODAL ===== --}}
    @if ($showModal)
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 pt-16" x-data
        x-on:keydown.escape.window="$wire.closeModal()">
        {{-- Backdrop --}}
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" wire:click="closeModal"></div>

        {{-- Panel --}}
        <div
            class="relative z-10 w-full max-w-2xl rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-gray-900 dark:ring-white/10">

            {{-- ── Header ── --}}
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-danger-50 dark:bg-danger-950">
                            <x-heroicon-o-no-symbol class="h-5 w-5 text-danger-600 dark:text-danger-400" />
                        </span>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                            @if ($isStyle2) Khóa khoảng thời gian @else Tô đen khung giờ @endif
                        </h2>
                        <p class="text-xs text-gray-400">
                            @if ($isStyle2)
                            Chặn khoảng ngày không cho khách đặt phòng
                            @else
                            Chặn khung giờ không cho đặt trong khoảng thời gian chỉ định
                            @endif
                        </p>
                    </div>
                </div>
                <button
                        wire:click="closeModal"
                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
            </div>

            {{-- ── Body ── --}}
            <div class="space-y-5 px-6 py-5">

                {{-- === FORM TÔ ĐEN MỚI === --}}
                <div
                    class="space-y-4 rounded-xl border border-gray-100 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-800/40">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                        @if ($isStyle2) Khóa khoảng ngày mới @else Thiết lập tô đen mới @endif
                    </p>

                    {{-- Chọn phòng --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Chọn phòng <span class="text-danger-500">*</span>
                            </label>
                        <select
                                wire:model.live="product_id"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                            >
                                <option value="">-- Chọn phòng --</option>
                                @foreach ($roomOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        @error('product_id')
                        <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Chọn khung giờ (checkbox grid) – chỉ hiển thị khi styles=1 --}}
                    @if (!$isStyle2)
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Chọn khung giờ <span class="text-danger-500">*</span>
                            </label>

                        @if (empty($timeslotOptions))
                        <p class="text-sm italic text-gray-400">
                            {{ $product_id ? 'Phòng này chưa có khung giờ nào.' : 'Chọn phòng trước để hiện khung giờ.'
                            }}
                        </p>
                        @else
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($timeslotOptions as $rtsId => $slotLabel)
                            <label
                                            class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2 text-sm transition
                                                {{ in_array($rtsId, $timeslot_ids)
                                                    ? 'border-primary-400 bg-primary-50 dark:border-primary-600 dark:bg-primary-950/30'
                                                    : 'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800' }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model="timeslot_ids"
                                                value="{{ $rtsId }}"
                                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                            />
                                            <span class="text-gray-700 dark:text-gray-200">{{ $slotLabel }}</span>
                                        </label>
                            @endforeach
                        </div>
                        @endif

                        @error('timeslot_ids')
                        <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                        @enderror
                    </div>
                    @endif {{-- end !isStyle2 --}}

                    {{-- Chọn ngày --}}
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Ngày bắt đầu <span class="text-danger-500">*</span>
                                </label>
                            <input
                                    type="date"
                                    wire:model="date_from"
                                    min="{{ now()->toDateString() }}"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                @error('date_from')
                            <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Ngày kết thúc <span class="text-danger-500">*</span>
                                </label>
                            <input
                                    type="date"
                                    wire:model="date_to"
                                    min="{{ $date_from ?? now()->toDateString() }}"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                />
                                @error('date_to')
                            <p class="mt-1 text-xs text-danger-500">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-400">Tô 1 ngày: chọn 2 ngày giống nhau</p>
                        </div>
                    </div>

                    {{-- Nút lưu --}}
                    <div class="flex justify-end pt-1">
                        <x-filament::button wire:click="saveBlock" wire:loading.attr="disabled" wire:target="saveBlock"
                            color="danger" icon="heroicon-o-no-symbol">
                            <span wire:loading.remove wire:target="saveBlock">
                                    @if ($isStyle2) Xác nhận khóa lịch @else Xác nhận tô đen @endif
                                </span>
                            <span wire:loading wire:target="saveBlock">Đang lưu…</span>
                        </x-filament::button>
                    </div>
                </div>

                {{-- === DANH SÁCH ĐÃ TÔ ĐEN === --}}
                @if ($product_id)
                <div>
                    <div class="mb-3 flex items-center gap-2">
                        <x-heroicon-o-calendar-days class="h-4 w-4 text-gray-400" />
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            @if ($isStyle2) Danh sách khoảng đã khóa @else Danh sách đã tô đen @endif
                        </p>
                        @if (count($blockedList) > 0)
                        <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                                        {{ count($blockedList) }}
                                    </span>
                        @endif
                    </div>

                    @if (empty($blockedList))
                    <div
                        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 py-8 dark:border-gray-700">
                        <x-heroicon-o-calendar class="h-8 w-8 text-gray-200 dark:text-gray-700" />
                        <p class="mt-2 text-sm text-gray-400">
                            @if ($isStyle2) Chưa có khoảng ngày nào bị khóa @else Chưa có khung giờ nào bị tô đen @endif
                        </p>
                    </div>
                    @elseif ($isStyle2)
                    {{-- Bảng danh sách khoảng ngày bị khóa (styles=2) --}}
                    <div class="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-800">
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Từ ngày</th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Đến ngày</th>
                                    <th
                                        class="w-12 px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Xóa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                @foreach ($blockedList as $item)
                                <tr class="group transition hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                    wire:key="blockrange-{{ $item['index'] }}">
                                    <td class="px-4 py-2.5">
                                        <span class="flex items-center gap-1.5 text-danger-600 dark:text-danger-400">
                                                            <x-heroicon-o-calendar class="h-3.5 w-3.5 flex-shrink-0" />
                                                            {{ $item['start_display'] }}
                                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="flex items-center gap-1.5 text-danger-600 dark:text-danger-400">
                                                            <x-heroicon-o-calendar class="h-3.5 w-3.5 flex-shrink-0" />
                                                            {{ $item['end_display'] }}
                                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button
                                                            wire:click="removeBlockedRange({{ $item['index'] }})"
                                                            wire:confirm="Gỡ khóa khoảng {{ $item['start_display'] }} → {{ $item['end_display'] }}?"
                                                            wire:loading.attr="disabled"
                                                            class="inline-flex items-center justify-center rounded-lg p-1.5 text-gray-300 transition hover:bg-danger-50 hover:text-danger-500 dark:text-gray-600 dark:hover:bg-danger-950 dark:hover:text-danger-400"
                                                            title="Gỡ khóa khoảng này"
                                                        >
                                                            <x-heroicon-o-x-mark class="h-4 w-4" />
                                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    {{-- Bảng danh sách ngày bị tô đen (styles=1) --}}
                    <div class="overflow-hidden rounded-xl border border-gray-100 dark:border-gray-800">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-800">
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Khung giờ</th>
                                    <th
                                        class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Ngày bị chặn</th>
                                    <th
                                        class="w-12 px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Xóa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                @foreach ($blockedList as $item)
                                <tr class="group transition hover:bg-gray-50 dark:hover:bg-gray-800/50"
                                    wire:key="block-{{ $item['rts_id'] }}-{{ $item['date'] }}">
                                    <td class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                            <x-heroicon-o-clock class="h-3 w-3" />
                                                            {{ $item['label'] }}
                                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="flex items-center gap-1.5 text-danger-600 dark:text-danger-400">
                                                            <x-heroicon-o-x-circle class="h-3.5 w-3.5 flex-shrink-0" />
                                                            {{ $item['date_display'] }}
                                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button
                                                            wire:click="removeBlockedDate({{ $item['rts_id'] }}, '{{ $item['date'] }}')"
                                                            wire:confirm="Gỡ tô đen ngày {{ $item['date_display'] }} của '{{ $item['label'] }}'?"
                                                            wire:loading.attr="disabled"
                                                            class="inline-flex items-center justify-center rounded-lg p-1.5 text-gray-300 transition hover:bg-danger-50 hover:text-danger-500 dark:text-gray-600 dark:hover:bg-danger-950 dark:hover:text-danger-400"
                                                            title="Gỡ tô đen ngày này"
                                                        >
                                                            <x-heroicon-o-x-mark class="h-4 w-4" />
                                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
                @endif

            </div>

            {{-- ── Footer ── --}}
            <div class="flex justify-end border-t border-gray-100 px-6 py-4 dark:border-gray-800">
                <x-filament::button color="gray" wire:click="closeModal">
                    Đóng
                </x-filament::button>
            </div>

        </div>
    </div>
    @endif
</div>