@extends('minihouse::portal.layout')

@section('title', 'Thanh toán hoá đơn - Portal khách thuê')

@section('content')
    <a href="{{ route('minihouse.portal.invoices.show', $invoice->id) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Quay lại hoá đơn</a>

    <div class="mt-4 rounded-xl bg-white p-6 shadow-sm border border-gray-100 text-center">
        <h1 class="text-lg font-semibold text-gray-900">Quét mã để thanh toán</h1>

        <img src="{{ $payment['qr_image'] }}" alt="QR thanh toán" class="mx-auto mt-4 w-64 h-64">

        <p class="mt-4 text-sm text-gray-500">Số tiền cần thanh toán</p>
        <p class="text-2xl font-bold text-gray-900">{{ number_format((float) $payment['amount'], 0, ',', '.') }}đ</p>

        @if ($payment['expired_at'])
            <p class="mt-2 text-xs text-gray-400">Hết hạn lúc {{ \Illuminate\Support\Carbon::parse($payment['expired_at'])->format('H:i d/m/Y') }}</p>
        @endif

        @if (! empty($payment['bank_info']))
            <div class="mt-4 text-sm text-gray-600 text-left bg-gray-50 rounded-lg p-3 space-y-0.5">
                <div>Chủ tài khoản: <strong>{{ $payment['bank_info']['holder'] }}</strong></div>
                <div>Ngân hàng: {{ $payment['bank_info']['bank'] }}</div>
                <div>Số tài khoản: <strong>{{ $payment['bank_info']['account'] }}</strong></div>
            </div>
        @endif

        @if ($payment['open_url'])
            <a href="{{ $payment['open_url'] }}" target="_blank" rel="noopener" class="mt-4 block rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                {{ $payment['open_label'] }}
            </a>
        @endif

        <p class="mt-4 text-xs text-gray-400">Sau khi thanh toán thành công, hệ thống sẽ tự động cập nhật trạng thái hoá đơn trong ít phút.</p>
    </div>
@endsection
