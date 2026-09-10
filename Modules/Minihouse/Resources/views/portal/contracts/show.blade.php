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
