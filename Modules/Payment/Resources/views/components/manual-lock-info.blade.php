{{--
    Thay thế cho access-code-info.blade.php khi phòng dùng KHÓA THỦ CÔNG (has_manual_lock) — chi
    nhánh không có tài khoản TTLock nên không có gì để tra ở bảng access_codes (OrderForm chỉ tạo
    Placeholder này khi hasAccessCodeSection() xác định phòng là manual lock, xem OrderForm.php).
    Nhận vào: $manualPwd (?ManualLockPassword, xem ManualLockPassword::getForProductAndDate() —
    PHẢI truyền mốc "chốt đơn" paid_at/deposit_paid_at/created_at, KHÔNG phải checkin_date/now()
    — xem docblock trên hàm đó), $order.
--}}
@if(!$manualPwd || (!$manualPwd->gate_password && !$manualPwd->room_password))
<div class="rounded-2xl border border-dashed p-5 text-center" style="border-color: var(--boulder-80, #e5e7eb); background: var(--boulder-95, #f9fafb);">
    <p class="text-sm" style="color: var(--boulder-50, #6b7280);">Chưa có mật khẩu thủ công cho phòng này — liên hệ quản lý chi nhánh để cập nhật.</p>
</div>
@else
@php
    // Cùng ngôn ngữ ngữ nghĩa với ManualLockPasswordResource (success=đang hoạt động,
    // warning=sắp hết hạn, danger=đã hết hạn, gray=ngừng hoạt động) — map sang cùng bộ class
    // badge đã dùng ở access-code-info.blade.php để 2 card nhìn nhất quán.
    $statusClassMap = [
        'success' => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
        'danger'  => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
    ];
    $statusClass = $statusClassMap[$manualPwd->status_color] ?? null;
@endphp
<div class="rounded-2xl border p-5" style="border-color: var(--boulder-80, #e5e7eb); background: var(--boulder-99, #fff);">
    {{-- Header: nhãn + trạng thái — cùng bố cục với access-code-info.blade.php, chỉ đổi nhãn để
         phân biệt rõ đây là mật khẩu THỦ CÔNG, không phải mã TTLock tự động. --}}
    <div class="flex items-center justify-between gap-3 mb-4">
        <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--boulder-50, #6b7280);">Mã cổng (thủ công)</span>
        @if($statusClass)
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $statusClass }}">
                {{ $manualPwd->status_label }}
            </span>
        @else
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border"
                style="color: var(--boulder-30, #374151); background: var(--boulder-95, #f9fafb); border-color: var(--boulder-80, #e5e7eb);">
                {{ $manualPwd->status_label }}
            </span>
        @endif
    </div>

    {{-- Mã cổng + mã phòng — phòng thủ công có thể có 1 hoặc cả 2 loại mật khẩu, hiện đủ ô nào
         có giá trị, không ép luôn phải có cả 2 (giống cách assignAccessCode action ghép chuỗi
         thông báo cho khách, xem EditOrder.php). --}}
    <div class="space-y-3 mb-4">
        @if($manualPwd->gate_password)
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Mã cổng</p>
            <div class="flex items-center gap-3">
                <span id="manualGateCode" class="text-3xl sm:text-4xl font-bold font-mono tracking-[0.25em] text-primary-600">
                    {{ $manualPwd->gate_password }}
                </span>
                <button type="button" onclick="window.copyManualLockCode(this, 'manualGateCode')" title="Sao chép mã cổng"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg transition-colors shrink-0"
                    style="background: var(--boulder-20, #111827); color: var(--boulder-99, #fff);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>
        </div>
        @endif

        @if($manualPwd->room_password)
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Mã phòng</p>
            <div class="flex items-center gap-3">
                <span id="manualRoomCode" class="text-2xl sm:text-3xl font-bold font-mono tracking-[0.25em]" style="color: var(--boulder-30, #374151);">
                    {{ $manualPwd->room_password }}
                </span>
                <button type="button" onclick="window.copyManualLockCode(this, 'manualRoomCode')" title="Sao chép mã phòng"
                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg transition-colors shrink-0"
                    style="background: var(--boulder-20, #111827); color: var(--boulder-99, #fff);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>
        </div>
        @endif
    </div>

    {{-- Hiệu lực — cùng bố cục grid 2 cột với access-code-info.blade.php. --}}
    @if($manualPwd->valid_from || $manualPwd->valid_until)
    <div class="grid grid-cols-2 gap-3 mb-1" style="padding-top: 0.75rem; border-top: 1px solid var(--boulder-95, #f3f4f6);">
        @if($manualPwd->valid_from)
        <div class="rounded-xl border p-3 text-center" style="border-color: var(--boulder-80, #e5e7eb);">
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Hiệu lực từ</p>
            <p class="text-sm font-bold" style="color: var(--boulder-30, #111827);">{{ $manualPwd->valid_from->format('d/m/Y') }}</p>
            <p class="text-xs mt-0.5" style="color: var(--boulder-50, #9ca3af);">{{ $manualPwd->valid_from->format('H:i') }}</p>
        </div>
        @endif

        @if($manualPwd->valid_until)
        <div class="rounded-xl border p-3 text-center" style="border-color: var(--boulder-80, #e5e7eb);">
            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--boulder-50, #9ca3af);">Hết hạn</p>
            <p class="text-sm font-bold" style="color: var(--boulder-30, #111827);">{{ $manualPwd->valid_until->format('d/m/Y') }}</p>
            <p class="text-xs mt-0.5" style="color: var(--boulder-50, #9ca3af);">{{ $manualPwd->valid_until->format('H:i') }}</p>
        </div>
        @endif
    </div>
    @endif
</div>

<script>
    window.copyManualLockCode = function (button, targetId) {
        const code = document.getElementById(targetId).textContent.trim();
        navigator.clipboard.writeText(code).then(() => {
            const original = button.innerHTML;
            button.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
            setTimeout(() => { button.innerHTML = original; }, 1200);
        });
    };
</script>
@endif
