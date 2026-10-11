<div
    x-data="xBlock('locationModal', $el)"
    @keydown.escape.window="closePopup()"
    x-cloak
>
    <script>
        window.__PROVINCES__ = @json($provinces);
    </script>
    <template x-teleport="body">
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[9999] flex items-center justify-center px-4"
            style="display: none;"
        >
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="closePopup()"></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                {{-- max-width/max-height dùng px/vh trực tiếp trong style (thay vì class max-w-sm)
                     để sau này chỉnh to/nhỏ cho dễ. Đã tăng từ 384px (max-w-sm cũ) lên 480px, và
                     80vh lên 85vh. --}}
                class="relative w-full rounded-2xl border border-gray-200 shadow-2xl overflow-hidden bg-white flex flex-col"
                style="max-width: 480px; max-height: 85vh;"
                @click.stop
            >
                {{-- Vị trí/kích thước dùng style px trực tiếp (thay vì class top-4/right-4/w-8/h-8)
                     để sau này chỉnh số cho dễ, khỏi tra bảng quy đổi class Tailwind. top:16px,
                     right:16px PHẢI >= bán kính bo góc của card cha (rounded-2xl = 16px) — nếu để
                     dưới 16px, phần bo góc (kết hợp overflow-hidden) sẽ cắt mất 1 phần hình tròn
                     của nút (đúng lỗi giao diện đã gặp trước đó). --}}
                <button
                    type="button"
                    @click="closePopup()"
                    aria-label="Đóng"
                    style="position:absolute; top:3px; right:5px; width:32px; height:32px;"
                    class="flex items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:text-gray-700 hover:bg-gray-200 transition-colors z-10"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div class="p-6 pb-4 shrink-0">
                    <p class="text-lg font-semibold text-gray-900 mb-1 pr-8">Bạn đang ở khu vực nào?</p>
                    <p class="text-sm text-gray-500 mb-4">Chọn khu vực để xem đầy đủ phòng và ưu đãi gần bạn.</p>

                    <button
                        type="button"
                        @click="detectLocation()"
                        :disabled="detecting"
                        class="w-full mb-3 rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                        style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                    >
                        <svg x-show="detecting" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <svg x-show="!detecting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span x-text="detecting ? 'Đang định vị...' : 'Dùng vị trí hiện tại'"></span>
                    </button>

                    <input
                        x-model="search"
                        type="text"
                        placeholder="Tìm tỉnh/thành..."
                        class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                        style="--tw-ring-color: {{ $primaryHex }}40;"
                    >

                    <div x-show="error" x-cloak class="mt-3 text-sm text-red-500 flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <span x-text="error"></span>
                    </div>
                </div>

                <div class="overflow-y-auto px-3 pb-4" style="flex: 1 1 auto;">
                    <template x-if="loading">
                        <p class="text-center text-sm text-gray-400 py-6">Đang tải danh sách...</p>
                    </template>

                    <template x-if="!loading && loaded && !filteredProvinces.length">
                        <p class="text-center text-sm text-gray-400 py-6">Không tìm thấy tỉnh/thành phù hợp.</p>
                    </template>

                    <template x-for="province in filteredProvinces" :key="province.id">
                        <button
                            type="button"
                            @click="selectProvince(province)"
                            class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-left text-sm text-gray-800 hover:bg-gray-50 transition-colors"
                        >
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span x-text="province.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
