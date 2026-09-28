@extends('minihouse::portal.layout')

@section('title', 'Chi tiết hoá đơn - Portal khách thuê')

@section('content')
    @php
        [$statusLabel, $statusBadge] = match ($invoice->status) {
            \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => ['Đã thanh toán', 'mh-badge-green'],
            \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => ['Thanh toán một phần', 'mh-badge-yellow'],
            default => ['Chưa thanh toán', 'mh-badge-red'],
        };
    @endphp

    <a href="{{ route('minihouse.portal.invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-900 transition">&larr; Danh sách hoá đơn</a>

    <div class="mt-3 flex items-center justify-between">
        <h1 class="text-xl font-bold text-gray-900 mh-heading">Hoá đơn tháng {{ $invoice->month?->format('m/Y') }}</h1>
        <span class="mh-badge {{ $statusBadge }}">{{ $statusLabel }}</span>
    </div>
    <p class="text-sm text-gray-500">Phòng {{ $invoice->contract?->room?->code ?? '—' }} — {{ $invoice->contract?->room?->building?->name ?? '—' }}</p>

    <div class="mt-4 mh-card mh-card-pad">
        <div class="space-y-2.5 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Tiền phòng</span><span class="text-gray-900 mh-tabular">{{ number_format((float) $invoice->room_price, 0, ',', '.') }}đ</span></div>
            @if ($invoice->electric_amount > 0)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Tiền điện ({{ $invoice->electric_start }} &rarr; {{ $invoice->electric_end }})</span><span class="text-gray-900 mh-tabular">{{ number_format((float) $invoice->electric_amount, 0, ',', '.') }}đ</span></div>
            @endif
            @if ($invoice->water_amount > 0)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Tiền nước ({{ $invoice->water_start }} &rarr; {{ $invoice->water_end }})</span><span class="text-gray-900 mh-tabular">{{ number_format((float) $invoice->water_amount, 0, ',', '.') }}đ</span></div>
            @endif
            @foreach ($invoice->items as $item)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">{{ $item->name }}</span><span class="text-gray-900 mh-tabular">{{ number_format((float) $item->amount, 0, ',', '.') }}đ</span></div>
            @endforeach

            <div class="flex justify-between pt-3 mt-1 border-t-2" style="border-color: var(--mh-border);"><span class="font-semibold text-gray-900">Tổng cộng tháng này</span><span class="font-semibold text-gray-900 mh-tabular">{{ number_format((float) $invoice->total_amount, 0, ',', '.') }}đ</span></div>

            @if ($invoice->amount_paid > 0)
                <div class="flex justify-between"><span class="text-gray-500">Đã thanh toán</span><span class="text-green-600 font-medium mh-tabular">{{ number_format((float) $invoice->amount_paid, 0, ',', '.') }}đ</span></div>
            @endif

            <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);">
                <span class="font-semibold text-gray-900">Còn lại (hoá đơn này)</span>
                <span class="font-bold mh-tabular {{ $invoice->remainingAmount() > 0 ? 'text-red-600' : 'text-green-600' }}">{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}đ</span>
            </div>

            @php
                $previousDebt = \Modules\Minihouse\App\Services\InvoiceContentRenderer::previousDebt($invoice);
            @endphp
            @if ($previousDebt > 0)
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="text-gray-500">Nợ cộng dồn tháng trước</span><span class="text-red-600 mh-tabular">{{ number_format($previousDebt, 0, ',', '.') }}đ</span></div>
                <div class="flex justify-between pt-2.5 border-t" style="border-color: var(--mh-border);"><span class="font-bold text-gray-900">Tổng phải trả</span><span class="font-bold text-red-600 mh-tabular">{{ number_format(\Modules\Minihouse\App\Services\InvoiceContentRenderer::totalOwed($invoice), 0, ',', '.') }}đ</span></div>
            @endif
        </div>
    </div>

    @if ($invoice->remainingAmount() > 0)
        <form method="POST" action="{{ route('minihouse.portal.invoices.pay', $invoice->id) }}" class="mt-4">
            @csrf
            <button type="submit" class="mh-btn-primary">Thanh toán trực tuyến</button>
        </form>
    @endif
@endsection
