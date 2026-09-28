@extends('minihouse::portal.layout')

@section('title', 'Xe của tôi - Portal khách thuê')

@section('content')
    @php
        $types = \Modules\Minihouse\App\Models\Vehicle::TYPES;
        $statusBadge = [
            'pending'  => 'mh-badge-yellow',
            'active'   => 'mh-badge-green',
            'inactive' => 'mh-badge-gray',
            'rejected' => 'mh-badge-red',
        ];
    @endphp

    <h1 class="text-xl font-bold text-gray-900 mh-heading">Xe của tôi</h1>
    <p class="mt-1 text-sm text-gray-500">Khai báo xe gửi tại toà nhà — nhân viên sẽ duyệt, xe được duyệt mới tính phí gửi xe theo bảng giá của toà.</p>

    @if ($errors->any())
        <div class="mt-3 rounded-xl bg-white border border-red-200 text-red-700 text-sm px-4 py-3 shadow-sm">{{ $errors->first() }}</div>
    @endif

    <div class="mt-4 space-y-2.5">
        @forelse ($vehicles as $vehicle)
            <div class="mh-card mh-card-pad">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-base font-bold text-gray-900 mh-tabular">{{ $vehicle->plate_display }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $types[$vehicle->vehicle_type] ?? $vehicle->vehicle_type }}
                            @if ($vehicle->name) · {{ $vehicle->name }} @endif
                        </div>
                    </div>
                    <span class="mh-badge {{ $statusBadge[$vehicle->status] ?? 'mh-badge-gray' }}">{{ $vehicle->statusLabel() }}</span>
                </div>

                @if ($vehicle->documentPhotoUrl())
                    <a href="{{ $vehicle->documentPhotoUrl() }}" target="_blank" class="mt-3 inline-block">
                        <img src="{{ $vehicle->documentPhotoUrl() }}" alt="Giấy tờ xe" class="h-16 w-24 rounded-lg object-cover border" style="border-color: var(--mh-border);">
                    </a>
                @endif

                @if ($vehicle->status === 'rejected' && $vehicle->reject_reason)
                    <div class="mt-2 text-xs text-red-600">Lý do từ chối: {{ $vehicle->reject_reason }}</div>
                @endif
                @if ($vehicle->status === 'active')
                    <div class="mt-2 text-xs text-gray-500 mh-tabular">
                        Phí gửi: {{ number_format(\Modules\Minihouse\App\Services\VehicleService::monthlyFee($vehicle), 0, ',', '.') }} đ/tháng
                    </div>
                @endif

                @if ($vehicle->status === 'pending')
                    <form method="POST" action="{{ route('minihouse.portal.vehicles.destroy', $vehicle->id) }}" class="mt-3" onsubmit="return confirm('Huỷ yêu cầu khai báo xe này?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Huỷ yêu cầu</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="mh-empty">Bạn chưa khai báo xe nào.</div>
        @endforelse
    </div>

    <div class="mt-6 mh-card mh-card-pad">
        <h2 class="text-sm font-bold text-gray-900 mh-heading">Khai báo xe mới</h2>

        @if ($contracts->isEmpty())
            <p class="mt-2 text-sm text-gray-500">Bạn chưa có hợp đồng đang hiệu lực nên chưa khai báo xe được.</p>
        @else
            <form method="POST" action="{{ route('minihouse.portal.vehicles.store') }}" enctype="multipart/form-data" class="mt-3 space-y-3.5">
                @csrf

                @if ($contracts->count() > 1)
                    <div>
                        <label class="mh-field-label">Hợp đồng (phòng)</label>
                        <select name="contract_id" class="mh-input">
                            @foreach ($contracts as $contract)
                                <option value="{{ $contract->id }}" @selected((int) old('contract_id') === $contract->id)>Phòng {{ $contract->room?->code }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="mh-field-label">Biển số <span class="text-red-500">*</span></label>
                    <input type="text" name="plate" value="{{ old('plate') }}" maxlength="30" required placeholder="VD: 59A1-123.45" class="mh-input uppercase">
                </div>

                <div>
                    <label class="mh-field-label">Loại xe <span class="text-red-500">*</span></label>
                    <select name="vehicle_type" required class="mh-input">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected(old('vehicle_type', 'motorbike') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mh-field-label">Tên xe <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="VD: Honda Vision" class="mh-input">
                </div>

                <div>
                    <label class="mh-field-label">Ảnh giấy tờ xe (không bắt buộc)</label>
                    <input type="file" name="document_photo" accept="image/*" class="w-full text-sm text-gray-600">
                </div>

                <button type="submit" class="mh-btn-primary">Gửi khai báo</button>
            </form>
        @endif
    </div>
@endsection
