@extends('minihouse::portal.layout')

@section('title', 'Xe của tôi - Portal khách thuê')

@section('content')
    @php
        $types = \Modules\Minihouse\App\Models\Vehicle::TYPES;
        $statusStyle = [
            'pending'  => 'bg-yellow-100 text-yellow-800',
            'active'   => 'bg-green-100 text-green-800',
            'inactive' => 'bg-gray-100 text-gray-600',
            'rejected' => 'bg-red-100 text-red-700',
        ];
    @endphp

    <h1 class="text-xl font-semibold text-gray-900">Xe của tôi</h1>
    <p class="mt-1 text-sm text-gray-500">Khai báo xe gửi tại toà nhà — nhân viên sẽ duyệt, xe được duyệt mới tính phí gửi xe theo bảng giá của toà.</p>

    @if ($errors->any())
        <div class="mt-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
    @endif

    <div class="mt-4 space-y-2">
        @forelse ($vehicles as $vehicle)
            <div class="rounded-xl bg-white p-4 shadow-sm border border-gray-100">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-base font-semibold text-gray-900">{{ $vehicle->plate_display }}</div>
                        <div class="text-xs text-gray-500">
                            {{ $types[$vehicle->vehicle_type] ?? $vehicle->vehicle_type }}
                            @if ($vehicle->name) · {{ $vehicle->name }} @endif
                        </div>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusStyle[$vehicle->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $vehicle->statusLabel() }}
                    </span>
                </div>

                @if ($vehicle->documentPhotoUrl())
                    <a href="{{ $vehicle->documentPhotoUrl() }}" target="_blank" class="mt-2 inline-block">
                        <img src="{{ $vehicle->documentPhotoUrl() }}" alt="Giấy tờ xe" class="h-16 w-24 rounded-lg object-cover border border-gray-200">
                    </a>
                @endif

                @if ($vehicle->status === 'rejected' && $vehicle->reject_reason)
                    <div class="mt-2 text-xs text-red-600">Lý do từ chối: {{ $vehicle->reject_reason }}</div>
                @endif
                @if ($vehicle->status === 'active')
                    <div class="mt-2 text-xs text-gray-500">
                        Phí gửi: {{ number_format(\Modules\Minihouse\App\Services\VehicleService::monthlyFee($vehicle), 0, ',', '.') }} đ/tháng
                    </div>
                @endif

                @if ($vehicle->status === 'pending')
                    <form method="POST" action="{{ route('minihouse.portal.vehicles.destroy', $vehicle->id) }}" class="mt-3" onsubmit="return confirm('Huỷ yêu cầu khai báo xe này?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 hover:underline">Huỷ yêu cầu</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="rounded-xl bg-white p-6 text-center text-sm text-gray-400 border border-gray-100">Bạn chưa khai báo xe nào.</div>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <h2 class="text-sm font-semibold text-gray-900">Khai báo xe mới</h2>

        @if ($contracts->isEmpty())
            <p class="mt-2 text-sm text-gray-500">Bạn chưa có hợp đồng đang hiệu lực nên chưa khai báo xe được.</p>
        @else
            <form method="POST" action="{{ route('minihouse.portal.vehicles.store') }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                @csrf

                @if ($contracts->count() > 1)
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hợp đồng (phòng)</label>
                        <select name="contract_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            @foreach ($contracts as $contract)
                                <option value="{{ $contract->id }}" @selected((int) old('contract_id') === $contract->id)>Phòng {{ $contract->room?->code }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Biển số <span class="text-red-500">*</span></label>
                    <input type="text" name="plate" value="{{ old('plate') }}" maxlength="30" required placeholder="VD: 59A1-123.45"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-gray-900/10">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Loại xe <span class="text-red-500">*</span></label>
                    <select name="vehicle_type" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected(old('vehicle_type', 'motorbike') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tên xe <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required placeholder="VD: Honda Vision"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ảnh giấy tờ xe (không bắt buộc)</label>
                    <input type="file" name="document_photo" accept="image/*" class="w-full text-sm">
                </div>

                <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                    Gửi khai báo
                </button>
            </form>
        @endif
    </div>
@endsection
