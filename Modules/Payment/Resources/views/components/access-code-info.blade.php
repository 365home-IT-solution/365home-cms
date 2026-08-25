@if(!$code)
<div class="rounded-2xl border border-dashed p-5 text-center" style="border-color: var(--boulder-80, #e5e7eb); background: var(--boulder-95, #f9fafb);">
    <p class="text-sm" style="color: var(--boulder-50, #6b7280);">Chưa có mã cổng</p>
</div>
@else
@php
    // Chỉ 2 trạng thái "active"/"expired" mang ý nghĩa (thành công/lỗi) mới giữ màu ngữ nghĩa
    // xanh lá/đỏ — trạng thái còn lại (disabled/khác) dùng màu trung tính "boulder" như phần còn
    // lại của card, không tự bịa thêm 1 tông màu xám khác.
    $statusStyles = [
        'active'   => ['label' => 'Đang hoạt động', 'class' => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20'],
        'expired'  => ['label' => 'Hết hạn', 'class' => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20'],
    ];
    // 'active' hiển thị gọn là "Hoạt động" thay vì "Đang hoạt động".
    $statusStyles['active']['label'] = 'Hoạt động';
    $status = $statusStyles[$code->status] ?? null;
    $statusLabel = $status['label'] ?? ($code->status === 'disabled' ? 'Vô hiệu hóa' : $code->status);
@endphp
{{-- Card cùng ngôn ngữ với các Section khác của form (rounded-2xl, viền + nền theo bảng màu
     "boulder") — component này không còn bị Filament Section bọc ngoài nữa (xem OrderForm.php),
     nên tự nó phải là 1 card hoàn chỉnh, không cần khung lồng khung. --}}
<div class="rounded-2xl border p-5" style="border-color: var(--boulder-80, #e5e7eb); background: var(--boulder-99, #fff);">
    {{-- Header: nhãn + trạng thái (không icon, theo yêu cầu) --}}
    <div class="flex items-center justify-between gap-3 mb-4">
        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--boulder-50, #6b7280);">Mã cổng</span>
        @if($status)
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $status['class'] }}">
                {{ $status['label'] }}
            </span>
        @else
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border"
                style="color: var(--boulder-30, #374151); background: var(--boulder-95, #f9fafb); border-color: var(--boulder-80, #e5e7eb);">
                {{ $statusLabel }}
            </span>
        @endif
    </div>

    {{-- Mã cổng --}}
    <div class="flex items-center gap-3 mb-4">
        {{-- text-primary-600: màu primary CẤU HÌNH THẬT của panel Filament (đổi theo GeneralSettings
             ->site_theme, xem AdminPanelProvider::colors()) — var(--color-primary) trước đây chỉ tồn
             tại ở layout phía client (BladeThemeV1), KHÔNG được định nghĩa trong admin nên luôn rơi
             về màu fallback tím cứng, không phải màu thương hiệu thật. --}}
        <span id="accessCode" class="text-3xl sm:text-4xl font-bold font-mono tracking-[0.25em] text-primary-600">
            {{ $code->code ?? '—' }}
        </span>
        <button type="button" onclick="window.copyAccessCode(this)" title="Sao chép mã"
            class="inline-flex items-center justify-center w-9 h-9 rounded-lg transition-colors shrink-0"
            style="background: var(--boulder-20, #111827); color: var(--boulder-99, #fff);">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
        </button>
    </div>

    {{-- Hiệu lực --}}
    @if($code->valid_from || $code->valid_until)
    <div class="grid grid-cols-2 gap-3 mb-4">
        @if($code->valid_from)
        <div class="rounded-xl border p-3 text-center" style="border-color: var(--boulder-80, #e5e7eb);">
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Hiệu lực từ</p>
            <p class="text-sm font-bold" style="color: var(--boulder-30, #111827);">{{ $code->valid_from->format('d/m/Y') }}</p>
            <p class="text-xs mt-0.5" style="color: var(--boulder-50, #9ca3af);">{{ $code->valid_from->format('H:i') }}</p>
        </div>
        @endif

        @if($code->valid_until)
        <div class="rounded-xl border p-3 text-center" style="border-color: var(--boulder-80, #e5e7eb);">
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Hết hạn</p>
            <p class="text-sm font-bold" style="color: var(--boulder-30, #111827);">{{ $code->valid_until->format('d/m/Y') }}</p>
            <p class="text-xs mt-0.5" style="color: var(--boulder-50, #9ca3af);">{{ $code->valid_until->format('H:i') }}</p>
        </div>
        @endif
    </div>
    @endif

    {{-- Vị trí cổng --}}
    @if($code->gate_location)
    <div class="pt-3 space-y-2" style="border-top: 1px solid var(--boulder-95, #f3f4f6);">
        <div class="flex items-center justify-between text-xs">
            <span class="flex items-center gap-1" style="color: var(--boulder-50, #6b7280);">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Vị trí cổng
            </span>
            <span class="font-semibold" style="color: var(--boulder-30, #374151);">{{ $code->gate_location }}</span>
        </div>
    </div>
    @endif
</div>

<script>
    window.copyAccessCode = function (button) {
        const code = document.getElementById('accessCode').textContent.trim();
        navigator.clipboard.writeText(code).then(() => {
            const original = button.innerHTML;
            button.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            setTimeout(() => { button.innerHTML = original; }, 1200);
        });
    };
</script>
@endif
