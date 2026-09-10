@extends('minihouse::portal.layout')

@section('title', 'Hợp đồng - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Hợp đồng</h1>

    @if ($contracts->isEmpty())
        <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100 text-sm text-gray-500">
            Chưa có hợp đồng nào.
        </div>
    @else
        <div class="mt-4 space-y-2">
            @foreach ($contracts as $contract)
                @php
                    [$statusLabel, $statusColor] = match ($contract->status) {
                        \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE => ['Đang hiệu lực', 'bg-green-50 text-green-700'],
                        \Modules\Minihouse\App\Models\Contract::STATUS_EXPIRED => ['Hết hạn', 'bg-gray-100 text-gray-600'],
                        \Modules\Minihouse\App\Models\Contract::STATUS_CANCELLED => ['Đã huỷ', 'bg-red-50 text-red-700'],
                        default => ['—', 'bg-gray-100 text-gray-600'],
                    };
                @endphp
                <a href="{{ route('minihouse.portal.contracts.show', $contract->id) }}" class="block rounded-xl bg-white p-4 shadow-sm border border-gray-100 hover:border-gray-300 transition">
                    <div class="flex items-center justify-between">
                        <div class="font-medium text-gray-900">Phòng {{ $contract->room?->code ?? '—' }}</div>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusColor }}">{{ $statusLabel }}</span>
                    </div>
                    <div class="mt-1 text-sm text-gray-500">{{ $contract->room?->building?->name ?? '—' }}</div>
                    <div class="mt-1 text-xs text-gray-400">
                        Từ {{ $contract->start_date?->format('d/m/Y') }}
                        @if ($contract->end_date)
                            đến {{ $contract->end_date->format('d/m/Y') }}
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
