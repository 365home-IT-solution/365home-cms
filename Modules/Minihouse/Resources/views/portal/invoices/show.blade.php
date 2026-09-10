@extends('minihouse::portal.layout')

@section('title', 'Chi tiết hoá đơn - Portal khách thuê')

@section('content')
    @php
        $statusLabel = match ($invoice->status) {
            \Modules\Minihouse\App\Models\Invoice::STATUS_PAID => 'Đã thanh toán',
            \Modules\Minihouse\App\Models\Invoice::STATUS_PARTIAL => 'Thanh toán một phần',
            default => 'Chưa thanh toán',
        };
    @endphp

    <a href="{{ route('minihouse.portal.invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Quay lại danh sách hoá đơn</a>

    <h1 class="mt-2 text-xl font-semibold text-gray-900">Hoá đơn tháng {{ $invoice->month?->format('m/Y') }}</h1>
    <p class="text-sm text-gray-500">Phòng {{ $invoice->contract?->room?->code ?? '—' }} — {{ $invoice->contract?->room?->building?->name ?? '—' }}</p>

    <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b border-gray-100">
                    <td class="py-2 text-gray-500">Tiền phòng</td>
                    <td class="py-2 text-right text-gray-900">{{ number_format((float) $invoice->room_price, 0, ',', '.') }}đ</td>
                </tr>
                @if ($invoice->electric_amount > 0)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-500">Tiền điện ({{ $invoice->electric_start }} → {{ $invoice->electric_end }})</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format((float) $invoice->electric_amount, 0, ',', '.') }}đ</td>
                    </tr>
                @endif
                @if ($invoice->water_amount > 0)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-500">Tiền nước ({{ $invoice->water_start }} → {{ $invoice->water_end }})</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format((float) $invoice->water_amount, 0, ',', '.') }}đ</td>
                    </tr>
                @endif
                @foreach ($invoice->items as $item)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-500">{{ $item->name }}</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format((float) $item->amount, 0, ',', '.') }}đ</td>
                    </tr>
                @endforeach
                <tr>
                    <td class="py-2 font-semibold text-gray-900">Tổng cộng</td>
                    <td class="py-2 text-right font-semibold text-gray-900">{{ number_format((float) $invoice->total_amount, 0, ',', '.') }}đ</td>
                </tr>
                @if ($invoice->amount_paid > 0)
                    <tr>
                        <td class="py-2 text-gray-500">Đã thanh toán</td>
                        <td class="py-2 text-right text-green-600">{{ number_format((float) $invoice->amount_paid, 0, ',', '.') }}đ</td>
                    </tr>
                @endif
                <tr>
                    <td class="py-2 font-semibold text-gray-900">Còn lại</td>
                    <td class="py-2 text-right font-bold {{ $invoice->remainingAmount() > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ number_format($invoice->remainingAmount(), 0, ',', '.') }}đ
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-gray-500">Trạng thái: <strong>{{ $statusLabel }}</strong></div>

    @if ($invoice->remainingAmount() > 0)
        <form method="POST" action="{{ route('minihouse.portal.invoices.pay', $invoice->id) }}" class="mt-4">
            @csrf
            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                Thanh toán trực tuyến
            </button>
        </form>
    @endif
@endsection
