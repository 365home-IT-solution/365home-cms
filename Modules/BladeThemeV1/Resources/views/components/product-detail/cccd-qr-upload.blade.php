{{-- 1 ô tải ảnh CCCD mặt có mã QR. Ảnh được quét ngay khi upload xong (xem HandlesCccdQrScan) —
     chỉ báo hợp lệ (viền xanh + dấu tích) hoặc lỗi, KHÔNG hiển thị thông tin đọc được.
     Tham số: $field (property Livewire, vd 'cccd_qr_image' / 'cccdQrImageExtra.0'), $scan (mục trong
     $cccdScanStatus hoặc null), $label. --}}
<div class="space-y-2">
    <label
        class="relative flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed cursor-pointer transition-all overflow-hidden min-h-[9rem] py-5 group
        {{ $errors->has($field) ? 'border-red-600' : (($scan['ok'] ?? false) ? 'border-green-400 bg-green-50' : 'border-[#DDDDDD] hover:border-[#B0B0B0] bg-[#FAFAFA] hover:bg-[#F7F7F7]') }}">
        <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only"
            onchange="processAndUpload(this, '{{ $field }}', {maxSize: 2400, quality: 0.92})" />
        <div wire:ignore class="contents">
            <div id="loading-{{ $field }}" class="hidden absolute inset-0 bg-white/90 backdrop-blur-sm z-20">
                <div class="flex flex-col items-center justify-center h-full">
                    <div class="animate-spin rounded-full h-8 w-8 border-3 border-black border-t-primary mb-3"></div>
                    <p class="text-xs font-medium text-black mb-2" id="status-{{ $field }}">Đang xử lý...</p>
                    <div class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                        <div id="progress-{{ $field }}" class="h-full bg-primary rounded-full transition-all duration-300"
                            style="width: 0%"></div>
                    </div>
                </div>
            </div>
            <img id="preview-{{ $field }}" src="" class="hidden absolute inset-0 w-full h-full object-contain bg-black/5"
                alt="{{ $label }}" />
            <div
                class="absolute inset-0 bg-black/20 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                <div class="bg-white/90 rounded-lg px-3 py-1 text-xs font-medium text-black">Đổi ảnh</div>
            </div>
            <div id="checkmark-{{ $field }}" class="hidden"></div>
        </div>
        @if ($scan['ok'] ?? false)
            <div class="absolute top-2 right-2 z-10 flex items-center gap-1 bg-green-500 rounded-full px-2 py-0.5 shadow">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M5 13l4 4L19 7" />
                </svg>
                <span class="text-white text-[10px] font-bold">CCCD hợp lệ</span>
            </div>
        @endif
        <div id="placeholder-{{ $field }}" class="px-4">
            <div class="h-9 w-9 rounded-full bg-[#F0F0F0] flex items-center justify-center mx-auto">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-[#717171]">
                    <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2" />
                    <rect x="7" y="7" width="4" height="4" rx="0.5" />
                    <rect x="13" y="13" width="4" height="4" rx="0.5" />
                    <path d="M13 7h4v2M7 15v2h2" />
                </svg>
            </div>
            <div class="text-center mt-2">
                <p class="text-xs font-semibold text-[#222222]">{{ $label }}</p>
                <p class="text-[11px] text-[#717171] mt-0.5">Chụp thẳng, đủ sáng, mã QR rõ nét — không chụp lại màn hình</p>
            </div>
        </div>
    </label>

    @error($field)
        <p class="text-[11px] text-red-600 font-medium">{{ $message }}</p>
    @else
        @if (isset($scan['ok']) && !$scan['ok'])
            <p class="text-[11px] text-red-600 font-medium">{{ $scan['error'] }}</p>
        @endif
    @enderror
</div>
