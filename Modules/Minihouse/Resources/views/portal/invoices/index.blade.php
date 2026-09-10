@extends('minihouse::portal.layout')

@section('title', 'Hoá đơn - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Hoá đơn</h1>

    @if ($invoices->isEmpty())
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100 text-sm text-gray-500">
            Chưa có hoá đơn nào.
        </div>
    @else
        <div class="mt-4 space-y-2">
            @foreach ($invoices as $invoice)
                @php
                    $statusLabel = match ($invoice->status) {
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => 'Đã thanh toán',
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'Thanh toán một phần',
                        default => 'Chưa thanh toán',
                    };
                    $statusColor = match ($invoice->status) {
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => 'bg-green-50 text-green-700',
                        \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'bg-yellow-50 text-yellow-700',
                        default => 'bg-red-50 text-red-700',
                    };
                @endphp
                <a href="{{ route('minihouse.portal.invoices.show', $invoice->id) }}" class="block rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-gray-900">Tháng {{ $invoice->month?->format('m/Y') }}</div>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusColor }}">{{ $statusLabel }}</span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">Phòng {{ $invoice->contract?->room?->code ?? '—' }}</div>
                    <div class="mt-2 text-lg font-semibold text-gray-900">{{ number_format((float) $invoice->total_amount, 0, ',', '.') }}đ</div>
                </a>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $invoices->links() }}
        </div>
    @endif
@endsection
