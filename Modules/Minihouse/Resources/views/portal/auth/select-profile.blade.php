@extends('minihouse::portal.layout')

@section('title', 'Chọn hồ sơ - Portal khách thuê')

@section('content')
    <div class="flex flex-col items-center mt-6 sm:mt-12">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white font-bold text-2xl mh-heading" style="background: var(--mh-primary);">M</span>
        <h1 class="mt-4 text-xl font-bold text-gray-900 mh-heading">Chọn hồ sơ</h1>
        <p class="mt-1 text-sm text-gray-500 text-center">Số điện thoại này gắn với nhiều hồ sơ khách thuê<br>— chọn đúng hồ sơ bạn muốn xem.</p>
    </div>

    <div class="w-full max-w-sm mx-auto mt-6 space-y-2.5">
        @foreach ($tenants as $tenant)
            <form method="POST" action="{{ route('minihouse.portal.login.select-profile.submit') }}">
                @csrf
                <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">
                <button type="submit" class="mh-list-row w-full text-left">
                    <div>
                        <div class="font-semibold text-gray-900">{{ $tenant->fullname }}</div>
                        <div class="text-sm text-gray-500">{{ $tenant->room?->code ? 'Phòng ' . $tenant->room->code : 'Chưa gán phòng' }}</div>
                    </div>
                </button>
            </form>
        @endforeach
    </div>
@endsection
