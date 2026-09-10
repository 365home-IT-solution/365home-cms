@extends('minihouse::portal.layout')

@section('title', 'Lịch sử thanh toán - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Lịch sử thanh toán</h1>

    @if ($payments->isEmpty())
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100 text-sm text-gray-500">
            Chưa có giao dịch nào.
        </div>
    @else
        <div class="mt-4 space-y-2">
            @foreach ($payments as $payment)
                @php
                    $methodLabel = match ($payment->payment_method) {
                        \Modules\Minihouse\App\Models\InvoicePayment::METHOD_CASH => 'Tiền mặt',
                        \Modules\Minihouse\App\Models\InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                        default => 'Khác',
                    };
                    $statusLabel = $payment->isApproved() ? 'Đã xác nhận' : 'Chờ xác nhận';
                    $statusColor = $payment->isApproved() ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700';
                @endphp
                <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-gray-900">{{ number_format((float) $payment->amount, 0, ',', '.') }}đ</div>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusColor }}">{{ $statusLabel }}</span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">
                        Hoá đơn tháng {{ $payment->invoice?->month?->format('m/Y') ?? '—' }} — {{ $methodLabel }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">{{ $payment->paid_at?->format('d/m/Y') }}</div>
                    @if ($payment->note)
                        <div class="mt-1 text-xs text-gray-400">{{ $payment->note }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $payments->links() }}
        </div>
    @endif
@endsection
