@extends('minihouse::portal.layout')

@section('title', 'Nhập mã xác thực - Portal khách thuê')

@section('content')
    <div class="flex flex-col items-center mt-6 sm:mt-12">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white font-bold text-2xl mh-heading" style="background: var(--mh-primary);">M</span>
        <h1 class="mt-4 text-xl font-bold text-gray-900 mh-heading">Nhập mã xác thực</h1>
        <p class="mt-1 text-sm text-gray-500 text-center">Mã 6 số vừa được gửi tới số <strong class="text-gray-700">{{ $phone }}</strong><br>qua Zalo hoặc SMS — có hiệu lực trong 5 phút.</p>
    </div>

    <div class="w-full max-w-sm mx-auto mt-6 mh-card mh-card-pad" style="padding: 1.5rem;">
        <form method="POST" action="{{ route('minihouse.portal.login.verify.submit') }}" class="space-y-4">
            @csrf
            <div>
                <label class="mh-field-label">Mã xác thực</label>
                <input
                    type="text"
                    name="code"
                    inputmode="numeric"
                    maxlength="6"
                    placeholder="••••••"
                    required
                    autofocus
                    class="mh-input text-center text-xl tracking-[0.5em]"
                >
            </div>

            <button type="submit" class="mh-btn-primary">Xác nhận</button>
        </form>

        <form method="POST" action="{{ route('minihouse.portal.login.request-otp') }}" class="mt-3">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <button type="submit" class="w-full text-sm text-gray-500 hover:text-gray-700 hover:underline py-1">
                Chưa nhận được mã? Gửi lại
            </button>
        </form>

        <a href="{{ route('minihouse.portal.login') }}" class="block mt-1 text-center text-sm text-gray-400 hover:text-gray-600">
            Dùng số điện thoại khác
        </a>
    </div>
@endsection
