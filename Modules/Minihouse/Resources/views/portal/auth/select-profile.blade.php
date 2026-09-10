@extends('minihouse::portal.layout')

@section('title', 'Chọn hồ sơ - Portal khách thuê')

@section('content')
    <div class="w-full max-w-sm mx-auto mt-10 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <h1 class="text-lg font-semibold text-gray-900">Chọn hồ sơ</h1>
        <p class="mt-1 text-sm text-gray-500">Số điện thoại này gắn với nhiều hồ sơ khách thuê — chọn đúng hồ sơ bạn muốn xem.</p>

        <div class="mt-4 space-y-2">
            @foreach ($tenants as $tenant)
                <form method="POST" action="{{ route('minihouse.portal.login.select-profile.submit') }}">
                    @csrf
                    <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                    <button type="submit" class="w-full text-left rounded-lg border border-gray-200 px-4 py-3 hover:border-gray-400 hover:bg-gray-50 transition">
                        <div class="font-medium text-gray-900">{{ $tenant->fullname }}</div>
                        <div class="text-sm text-gray-500">{{ $tenant->room?->code ? 'Phòng ' . $tenant->room->code : 'Chưa gán phòng' }}</div>
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endsection
