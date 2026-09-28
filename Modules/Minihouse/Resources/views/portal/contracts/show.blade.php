@extends('minihouse::portal.layout')

@section('title', 'Chi tiết hợp đồng - Portal khách thuê')

@section('content')
    @php
        [$statusLabel, $statusColor] = match ($contract->status) {
            \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE => ['Đang hiệu lực', 'bg-green-50 text-green-700'],
            \Modules\Minihouse\App\Models\Contract::STATUS_EXPIRED => ['Hết hạn', 'bg-gray-100 text-gray-600'],
            \Modules\Minihouse\App\Models\Contract::STATUS_CANCELLED => ['Đã huỷ', 'bg-red-50 text-red-700'],
            default => ['—', 'bg-gray-100 text-gray-600'],
        };

        $files = [
            'contract_file'          => 'Hợp đồng (file)',
            'handover_file'          => 'Biên bản bàn giao (lúc nhận)',
            'deposit_receipt_file'   => 'Biên bản đặt cọc',
            'checkout_handover_file' => 'Biên bản bàn giao (lúc trả phòng)',
        ];
    @endphp

    <a href="{{ route('minihouse.portal.contracts.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Quay lại danh sách hợp đồng</a>

    <div class="mt-2 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900">Phòng {{ $contract->room?->code ?? '—' }}</h1>
        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusColor }}">{{ $statusLabel }}</span>
    </div>
    <p class="text-sm text-gray-500">{{ $contract->room?->building?->name ?? '—' }}</p>

    <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100">
                    <td class="py-2 text-gray-500">Giá thuê</td>
                    <td class="py-2 text-right text-gray-900">{{ number_format((float) $contract->monthly_price, 0, ',', '.') }}đ/tháng</td>
                </tr>
                <tr class="border-b border-gray-100">
                    <td class="py-2 text-gray-500">Tiền cọc</td>
                    <td class="py-2 text-right text-gray-900">{{ number_format((float) $contract->deposit_amount, 0, ',', '.') }}đ</td>
                </tr>
                <tr class="border-b border-gray-100">
                    <td class="py-2 text-gray-500">Ngày bắt đầu</td>
                    <td class="py-2 text-right text-gray-900">{{ $contract->start_date?->format('d/m/Y') }}</td>
                </tr>
                @if ($contract->end_date)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-500">Ngày kết thúc</td>
                        <td class="py-2 text-right text-gray-900">{{ $contract->end_date->format('d/m/Y') }}</td>
                    </tr>
                @endif
                @if ($contract->checkout_at)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-500">Ngày trả phòng thực tế</td>
                        <td class="py-2 text-right text-gray-900">{{ $contract->checkout_at->format('d/m/Y') }}</td>
                    </tr>
                    @if ($contract->deposit_refunded_amount)
                        <tr>
                            <td class="py-2 text-gray-500">Tiền cọc đã hoàn</td>
                            <td class="py-2 text-right text-gray-900">{{ number_format((float) $contract->deposit_refunded_amount, 0, ',', '.') }}đ</td>
                        </tr>
                    @endif
                @endif
            </tbody>
        </table>
    </div>

    @if ($lockCode || $canChangeCode)
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
            <div class="text-sm font-medium text-gray-900 mb-2">Mã cổng</div>

            @if ($lockCode)
                <div class="text-2xl font-semibold tracking-widest text-gray-900">{{ $lockCode }}</div>
            @else
                <p class="text-sm text-gray-500">Chưa có mã — liên hệ nhân viên toà nhà nếu bạn cần mở cổng bằng mã số.</p>
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
                    <input type="text" name="custom_code" inputmode="numeric" maxlength="9" value="{{ old('custom_code') }}"
                           placeholder="Tự chọn mã (bỏ trống để hệ thống tự tạo)"
                           class="text-sm rounded-lg border border-gray-300 px-3 py-1.5 w-64 max-w-full focus:outline-none focus:ring-1 focus:ring-gray-400">
                    <button type="button" onclick="document.getElementById('lockCodeConfirmModal').classList.remove('hidden')"
                            class="text-sm text-gray-700 border border-gray-300 rounded-lg px-3 py-1.5 hover:bg-gray-50 transition">
                        {{ $lockCode ? 'Đổi mã cổng' : 'Cấp mã cổng' }}
                    </button>
                </form>
                <p class="mt-1 text-xs text-gray-400">Nhập 4-9 chữ số nếu muốn tự chọn mã (không dùng số liên tiếp như 123456 hoặc số lặp như 111111 — hệ thống khoá sẽ từ chối), bỏ trống để tự sinh mã ngẫu nhiên.</p>

                {{-- Popup xác nhận riêng của trang (thay cho confirm() mặc định của trình duyệt —
                     xấu, không đổi được giao diện, không đồng bộ với style Tailwind của trang). --}}
                <div id="lockCodeConfirmModal" class="hidden fixed inset-0 z-20 flex items-center justify-center bg-black/40 px-4">
                    <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-lg">
                        <div class="text-base font-semibold text-gray-900">Đổi mã cổng?</div>
                        <p class="mt-1 text-sm text-gray-500">Mã cũ sẽ ngừng dùng được ngay và được thay bằng mã mới.</p>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" onclick="document.getElementById('lockCodeConfirmModal').classList.add('hidden')"
                                    class="text-sm text-gray-600 rounded-lg px-3 py-1.5 hover:bg-gray-50 transition">Huỷ</button>
                            <button type="button" onclick="document.getElementById('lockCodeForm').submit()"
                                    class="text-sm text-white bg-gray-900 rounded-lg px-3 py-1.5 hover:bg-gray-800 transition">Xác nhận</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($contract->contract_content)
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
            <div class="text-sm font-medium text-gray-900 mb-2">Nội dung hợp đồng</div>
            <div class="prose prose-sm max-w-none text-gray-600">{!! $contract->contract_content !!}</div>
        </div>
    @endif

    @php
        $hasAnyFile = collect($files)->keys()->contains(fn ($field) => filled($contract->{$field}));
    @endphp

    @if ($hasAnyFile)
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
            <div class="text-sm font-medium text-gray-900 mb-2">Giấy tờ đính kèm</div>
            <div class="space-y-2">
                @foreach ($files as $field => $label)
                    @if ($contract->{$field})
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($contract->{$field}) }}" target="_blank" rel="noopener" class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-gray-400 transition">
                            <span class="text-gray-700">{{ $label }}</span>
                            <span class="text-gray-400">Tải về &rarr;</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
@endsection
