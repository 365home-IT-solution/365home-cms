@extends('minihouse::portal.layout')

@section('title', 'Lịch sử thanh toán - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Lịch sử thanh toán</h1>

    @if ($payments->isEmpty())
        <div class="mt-4 mh-empty">Chưa có giao dịch nào.</div>
    @else
        <div class="mt-4 space-y-2.5">
            @foreach ($payments as $payment)
                @php
                    $methodLabel = match ($payment->payment_method) {
                        \Modules\Minihouse\App\Models\InvoicePayment::METHOD_CASH => 'Tiền mặt',
                        \Modules\Minihouse\App\Models\InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                        default => 'Khác',
                    };
                    $statusLabel = $payment->isApproved() ? 'Đã xác nhận' : 'Chờ xác nhận';
                    $statusBadge = $payment->isApproved() ? 'mh-badge-green' : 'mh-badge-yellow';
                @endphp
                <div class="mh-list-row">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold text-gray-900 mh-tabular">{{ number_format((float) $payment->amount, 0, ',', '.') }}đ</div>
                            <span class="mh-badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="mt-0.5 text-sm text-gray-500">
                            Hoá đơn tháng {{ $payment->invoice?->month?->format('m/Y') ?? '—' }} — {{ $methodLabel }}
                        </div>
                        <div class="mt-0.5 text-xs text-gray-400 mh-tabular">{{ $payment->paid_at?->format('d/m/Y') }}</div>
                        @if ($payment->note)
                            <div class="mt-0.5 text-xs text-gray-400">{{ $payment->note }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $payments->links() }}
        </div>
    @endif
@endsection
