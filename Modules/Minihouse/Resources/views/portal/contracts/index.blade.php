@extends('minihouse::portal.layout')

@section('title', 'Hợp đồng - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Hợp đồng</h1>

    @if ($contracts->isEmpty())
        <div class="mt-4 mh-empty">Chưa có hợp đồng nào.</div>
    @else
        <div class="mt-4 space-y-2.5">
            @foreach ($contracts as $contract)
                @php
                    [$statusLabel, $statusBadge] = match ($contract->status) {
                        \Modules\Minihouse\App\Models\Contract::STATUS_ACTIVE => ['Đang hiệu lực', 'mh-badge-green'],
                        \Modules\Minihouse\App\Models\Contract::STATUS_EXPIRED => ['Hết hạn', 'mh-badge-gray'],
                        \Modules\Minihouse\App\Models\Contract::STATUS_CANCELLED => ['Đã huỷ', 'mh-badge-red'],
                        default => ['—', 'mh-badge-gray'],
                    };
                @endphp
                <a href="{{ route('minihouse.portal.contracts.show', $contract->id) }}" class="mh-list-row">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <div class="font-semibold text-gray-900">Phòng {{ $contract->room?->code ?? '—' }}</div>
                            <span class="mh-badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="mt-0.5 text-sm text-gray-500 truncate">{{ $contract->room?->building?->name ?? '—' }}</div>
                        <div class="mt-0.5 text-xs text-gray-400 mh-tabular">
                            Từ {{ $contract->start_date?->format('d/m/Y') }}
                            @if ($contract->end_date)
                                đến {{ $contract->end_date->format('d/m/Y') }}
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
