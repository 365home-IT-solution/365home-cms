@extends('minihouse::portal.layout')

@section('title', 'Chi tiết hợp đồng - Portal khách thuê')

@section('content')
    @php
        [$statusLabel, $statusBadge] = match ($contract->status) {
            \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE => ['Đang hiệu lực', 'mh-badge-green'],
            \Modules\Minihouse\App\Models\Contract::STATUS_EXPIRED => ['Hết hạn', 'mh-badge-gray'],
            \Modules\Minihouse\App\Models\Contract::STATUS_CANCELLED => ['Đã huỷ', 'mh-badge-red'],
            default => ['—', 'mh-badge-gray'],
        };

        $files = [
            'contract_file'          => 'Hợp đồng (file)',
            'handover_file'          => 'Biên bản bàn giao (lúc nhận)',
            'deposit_receipt_file'   => 'Biên bản đặt cọc',
            'checkout_handover_file' => 'Biên bản bàn giao (lúc trả phòng)',
        ];

        $room = $contract->room;
        $contractIsCurrent = $contract->status === \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE
            && (! $contract->start_date || ! $contract->start_date->isFuture())
            && (! $contract->end_date || ! $contract->end_date->isPast());
        $canOpenByApp = $contractIsCurrent && $unlockLocks && ! $room?->emergency_locked_at;
    @endphp

    <a href="{{ route('minihouse.portal.contracts.index') }}" class="text-sm text-gray-500 hover:text-gray-900 transition">&larr; Danh sách hợp đồng</a>

    <div class="mt-3 flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900 mh-heading">Phòng {{ $contract->room?->code ?? '—' }}</h1>
        <span class="mh-badge {{ $statusBadge }}">{{ $statusLabel }}</span>
    </div>
    <p class="text-sm text-gray-500">{{ $contract->room?->building?->name ?? '—' }}</p>

    <div class="mt-4 mh-card mh-card-pad">
        <div class="space-y-2.5 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Giá thuê</span><span class="font-semibold text-gray-900 mh-tabular">{{ number_format((float) $contract->monthly_price, 0, ',', '.') }}đ/tháng</span></div>
            <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Tiền cọc</span><span class="font-semibold text-gray-900 mh-tabular">{{ number_format((float) $contract->deposit_amount, 0, ',', '.') }}đ</span></div>
            <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Ngày bắt đầu</span><span class="text-gray-900 mh-tabular">{{ $contract->start_date?->format('d/m/Y') }}</span></div>
            @if ($contract->end_date)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Ngày kết thúc</span><span class="text-gray-900 mh-tabular">{{ $contract->end_date->format('d/m/Y') }}</span></div>
            @endif
            @if ($contract->checkout_at)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Ngày trả phòng thực tế</span><span class="text-gray-900 mh-tabular">{{ $contract->checkout_at->format('d/m/Y') }}</span></div>
                @if ($contract->deposit_refunded_amount)
                    <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Tiền cọc đã hoàn</span><span class="text-gray-900 mh-tabular">{{ number_format((float) $contract->deposit_refunded_amount, 0, ',', '.') }}đ</span></div>
                @endif
            @endif
        </div>
    </div>

    @if ($unlockLocks && $contractIsCurrent)
        <div class="mt-4 mh-card mh-card-pad">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-gray-900">Mở khóa từ xa</div>
                    <p class="mt-1 text-xs text-gray-500">Chọn khóa cần mở — lệnh được gửi trực tiếp đến khóa TTLock.</p>
                </div>
                <span class="mh-badge {{ $room->emergency_locked_at ? 'mh-badge-red' : 'mh-badge-green' }}">
                    {{ $room->emergency_locked_at ? 'Đang khóa khẩn cấp' : 'Sẵn sàng' }}
                </span>
            </div>

            @if ($canOpenByApp)
                @error('unlock')
                    <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                @enderror
                {{-- 1 nút cho khoá phòng + 1 nút cho từng khoá cổng của toà nhà (TenantRoomUnlockService::locks()). --}}
                <div class="mt-4 space-y-2">
                    @foreach ($unlockLocks as $lock)
                        <form method="POST" action="{{ route('minihouse.portal.contracts.unlock', $contract->id) }}"
                              onsubmit="return confirm(@js('Bạn muốn mở khóa "' . $lock['name'] . '" ngay bây giờ?'))">
                            @csrf
                            <input type="hidden" name="target" value="{{ $lock['target'] }}">
                            <input type="hidden" name="lock_id" value="{{ $lock['lock_id'] }}">
                            <button type="submit" class="{{ $lock['target'] === 'room' ? 'mh-btn-primary' : 'mh-btn-secondary' }}" style="width: 100%;">
                                {{ $lock['target'] === 'room' ? 'Mở khóa phòng' : 'Mở khóa cổng' }} — {{ $lock['name'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-gray-400">Nếu khóa ngoại tuyến, hãy dùng mật mã hoặc thẻ dự phòng.</p>
            @else
                <div class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700">
                    Quyền mở cửa qua Portal đang bị khóa khẩn cấp. Vui lòng liên hệ chủ nhà hoặc nhân viên tòa nhà.
                </div>
            @endif
        </div>
    @endif

    @if ($lockCode || $canChangeCode)
        <div class="mt-4 mh-card mh-card-pad">
            {{-- Toà có khoá cổng: hiện RIÊNG "Mã phòng" và "Mã cổng" (kể cả khi 2 số trùng nhau — khách
                 khỏi phải đoán mã nào mở cửa nào). Toà không có khoá cổng: giữ tên cũ "Mã cổng". --}}
            @if ($lockCode)
                @if ($gateCode && $roomCode)
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Mã phòng</div>
                            <div class="mt-2 text-2xl font-extrabold tracking-[0.15em] mh-heading mh-tabular" style="color: var(--mh-primary);">{{ $roomCode }}#</div>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-gray-900">Mã cổng</div>
                            <div class="mt-2 text-2xl font-extrabold tracking-[0.15em] mh-heading mh-tabular" style="color: var(--mh-primary);">{{ $gateCode }}#</div>
                        </div>
                    </div>
                @else
                    <div class="text-sm font-semibold text-gray-900">Mã cổng</div>
                    <div class="mt-2 text-3xl font-extrabold tracking-[0.2em] mh-heading mh-tabular" style="color: var(--mh-primary);">{{ $lockCode }}#</div>
                @endif
            @else
                <div class="text-sm font-semibold text-gray-900">Mã cổng</div>
                <p class="mt-2 text-sm text-gray-500">Chưa có mã — liên hệ nhân viên toà nhà nếu bạn cần mở cổng bằng mã số.</p>
            @endif

            @if ($canChangeCode)
                @error('custom_code')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('lock_code')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
                <form id="lockCodeForm" method="POST" action="{{ route('minihouse.portal.contracts.lock-code.regenerate', $contract->id) }}" class="mt-3 flex flex-wrap items-center gap-2">
                    @csrf
                    @if ($separateGate)
                        <select name="target" class="mh-input" style="width: auto;">
                            <option value="room" @selected(old('target') === 'room')>Mã phòng</option>
                            <option value="gate" @selected(old('target') === 'gate')>Mã cổng</option>
                        </select>
                    @endif
                    <input type="text" name="custom_code" inputmode="numeric" maxlength="9" value="{{ old('custom_code') }}"
                           placeholder="Tự chọn mã (bỏ trống để hệ thống tự tạo)"
                           class="mh-input flex-1 min-w-[12rem]">
                    <button type="button" onclick="document.getElementById('lockCodeConfirmModal').classList.remove('hidden')" class="mh-btn-secondary">
                        {{ $lockCode ? 'Đổi mã cổng' : 'Cấp mã cổng' }}
                    </button>
                </form>
                <p class="mt-2 text-xs text-gray-400">Nhập 4-9 chữ số nếu muốn tự chọn mã (không dùng số liên tiếp như 123456 hoặc số lặp như 111111 — hệ thống khoá sẽ từ chối), bỏ trống để tự sinh mã ngẫu nhiên.</p>

                {{-- Popup xác nhận riêng của trang (thay cho confirm() mặc định của trình duyệt —
                     xấu, không đổi được giao diện, không đồng bộ với style của trang). --}}
                <div id="lockCodeConfirmModal" class="hidden fixed inset-0 z-20 flex items-center justify-center bg-black/40 px-4">
                    <div class="w-full max-w-sm mh-card mh-card-pad" style="box-shadow: 0 20px 40px rgba(20,23,43,0.18);">
                        <div class="text-base font-bold text-gray-900 mh-heading">Đổi mã cổng?</div>
                        <p class="mt-1 text-sm text-gray-500">Mã cũ sẽ ngừng dùng được ngay và được thay bằng mã mới.</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" onclick="document.getElementById('lockCodeConfirmModal').classList.add('hidden')" class="mh-btn-secondary">Huỷ</button>
                            <button type="button" onclick="document.getElementById('lockCodeForm').submit()" class="mh-btn-primary" style="width: auto; padding: 0.5rem 1rem;">Xác nhận</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($contract->contract_content)
        <div class="mt-4 mh-card mh-card-pad">
            <div class="text-sm font-semibold text-gray-900 mb-2">Nội dung hợp đồng</div>
            <div class="prose prose-sm max-w-none text-gray-600">{!! $contract->contract_content !!}</div>
        </div>
    @endif

    @php
        $hasAnyFile = collect($files)->keys()->contains(fn ($field) => filled($contract->{$field}));
    @endphp

    @if ($hasAnyFile)
        <div class="mt-4 mh-card mh-card-pad">
            <div class="text-sm font-semibold text-gray-900 mb-2">Giấy tờ đính kèm</div>
            <div class="space-y-2">
                @foreach ($files as $field => $label)
                    @if ($contract->{$field})
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($contract->{$field}) }}" target="_blank" rel="noopener" class="flex items-center justify-between rounded-xl border px-3.5 py-2.5 text-sm transition hover:border-gray-300" style="border-color: var(--mh-border);">
                            <span class="text-gray-700">{{ $label }}</span>
                            <span class="text-gray-400">Tải về &rarr;</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
@endsection
