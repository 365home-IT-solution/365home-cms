@extends('minihouse::portal.layout')

@section('title', 'Hoá đơn - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Hoá đơn</h1>

    @if ($invoices->isEmpty())
        <div class="mt-4 mh-empty">Chưa có hoá đơn nào.</div>
    @else
        <div class="mt-4 space-y-2.5">
            @foreach ($invoices as $invoice)
                @php
                    $statusLabel = match ($invoice->status) {
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => 'Đã thanh toán',
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'Thanh toán một phần',
                        default => 'Chưa thanh toán',
                    };
                    $statusBadge = match ($invoice->status) {
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => 'mh-badge-green',
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'mh-badge-yellow',
                        default => 'mh-badge-red',
                    };
                @endphp
                <a href="{{ route('minihouse.portal.invoices.show', $invoice->id) }}" class="mh-list-row">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold text-gray-900">Tháng {{ $invoice->month?->format('m/Y') }}</div>
                            <span class="mh-badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="mt-0.5 text-sm text-gray-500">Phòng {{ $invoice->contract?->room?->code ?? '—' }}</div>
                        <div class="mt-1 text-lg font-bold text-gray-900 mh-tabular">{{ number_format((float) $invoice->total_amount, 0, ',', '.') }}đ</div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $invoices->links() }}
        </div>
    @endif
@endsection
