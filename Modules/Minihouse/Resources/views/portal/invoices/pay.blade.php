@extends('minihouse::portal.layout')

@section('title', 'Thanh toán hoá đơn - Portal khách thuê')

@section('content')
    <a href="{{ route('minihouse.portal.invoices.show', $invoice->id) }}" class="text-sm text-gray-500 hover:text-gray-900 transition">&larr; Quay lại hoá đơn</a>

    <div class="mt-4 mh-card mh-card-pad text-center" style="padding: 2rem 1.5rem;">
        <h1 class="text-lg font-bold text-gray-900 mh-heading">Quét mã để thanh toán</h1>

        <div class="mx-auto mt-4 w-64 h-64 rounded-2xl border p-3" style="border-color: var(--mh-border);">
            <img src="{{ $payment['qr_image'] }}" alt="QR thanh toán" class="w-full h-full object-contain">
        </div>

        <p class="mt-5 text-sm text-gray-500">Số tiền cần thanh toán</p>
        <p class="text-3xl font-extrabold mh-heading mh-tabular" style="color: var(--mh-primary);">{{ number_format((float) $payment['amount'], 0, ',', '.') }}đ</p>

        @if ($payment['expired_at'])
            <p class="mt-2 text-xs text-gray-400">Hết hạn lúc {{ \Illuminate\Support\Carbon::parse($payment['expired_at'])->format('H:i d/m/Y') }}</p>
        @endif

        @if (! empty($payment['bank_info']))
            <div class="mt-4 text-sm text-gray-600 text-left rounded-xl p-3.5 space-y-1" style="background: var(--mh-bg);">
                <div>Chủ tài khoản: <strong class="text-gray-900">{{ $payment['bank_info']['holder'] }}</strong></div>
                <div>Ngân hàng: {{ $payment['bank_info']['bank'] }}</div>
                <div>Số tài khoản: <strong class="text-gray-900 mh-tabular">{{ $payment['bank_info']['account'] }}</strong></div>
            </div>
        @endif

        @if ($payment['open_url'])
            <a href="{{ $payment['open_url'] }}" target="_blank" rel="noopener" class="mh-btn-primary mt-4">
                {{ $payment['open_label'] }}
            </a>
        @endif

        {{-- VietQR (có bank_info) là mã QR TĨNH, không có webhook nào báo về hệ thống khi khách
        chuyển khoản — khác PayOS/MoMo/VNPay tự động cập nhật thật. Nói "hệ thống tự động cập nhật"
        cho cả VietQR là sai sự thật, khiến khách chờ mãi không thấy đổi rồi nhắn hỏi/khiếu nại. --}}
        @if (! empty($payment['bank_info']))
            <p class="mt-4 text-xs text-gray-400">Sau khi chuyển khoản, vui lòng chờ chủ nhà xác nhận đã nhận được tiền — hệ thống không tự động cập nhật với hình thức chuyển khoản này.</p>
        @else
            <p class="mt-4 text-xs text-gray-400">Sau khi thanh toán thành công, hệ thống sẽ tự động cập nhật trạng thái hoá đơn trong ít phút.</p>
        @endif
    </div>
@endsection
