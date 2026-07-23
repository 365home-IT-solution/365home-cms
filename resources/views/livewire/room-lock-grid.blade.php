<div x-data="{ open: false }"
     @open-room-lock-grid.window="open = true"
     @close-room-lock-grid.window="open = false">

    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 pt-12"
         x-on:keydown.escape.window="open = false; $wire.close()">

        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="open = false; $wire.close()"></div>

        <div class="relative z-10 w-full max-w-7xl rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-gray-900 dark:ring-white/10">

            {{-- Header --}}
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                            Khóa tạm thời (realtime) — {{ $productName }}
                        </h2>
                        <p class="text-xs text-gray-400">
                            Bấm vào khung giờ để giữ chỗ ngay — tự hết hạn sau vài phút nếu không thao tác tiếp, chưa tạo đơn.
                        </p>
                    </div>
                </div>
                <button @click="open = false; $wire.close()"
                    class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            {{-- Body: lưới bên trái + giá/khuyến mãi bên phải (giống hệt panel giá ở trang tạo đơn,
                 xem 'payment::components.total-amount-card') — cập nhật ngay khi bấm chọn/bỏ chọn
                 ô, không cần tạo đơn mới thấy giá. --}}
            <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                @if ($productId)
                    @if (empty($slots))
                        <p class="py-8 text-center text-sm italic text-gray-400">
                            Phòng này không có khung giờ (đặt theo ngày, không áp dụng khóa tạm thời kiểu này).
                        </p>
                    @else
                        <div class="grid grid-cols-1 gap-5 lg:grid-cols-10">
                            <div class="lg:col-span-7">
                                @include('payment::components.timeslot-grid-table', [
                                    'dates'         => $dates,
                                    'slots'         => $slots,
                                    'cells'         => $cells,
                                    'selectedSlots' => $selectedSlots,
                                    'itemKey'       => 'lock',
                                ])

                                <p class="mt-3 text-xs text-gray-400">
                                    Đang giữ: <strong>{{ count($selectedSlots) }}</strong> khung giờ.
                                </p>
                            </div>

                            <div class="rounded-xl border border-gray-100 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-800/40 lg:col-span-3">
                                @include('payment::components.total-amount-card', [
                                    'items' => $priceItems,
                                ])
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-800">
                <button type="button" wire:click="releaseAll"
                    wire:loading.attr="disabled"
                    @if (empty($selectedSlots)) disabled @endif
                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                    Bỏ giữ tất cả
                </button>
                <button type="button" wire:click="goToBooking"
                    wire:loading.attr="disabled"
                    @if (empty($selectedSlots)) disabled @endif
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-40">
                    Đặt phòng ({{ count($selectedSlots) }} khung giờ) →
                </button>
            </div>

        </div>
    </div>
</div>
