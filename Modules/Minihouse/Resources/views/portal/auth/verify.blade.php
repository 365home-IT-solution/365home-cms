@extends('minihouse::portal.layout')

@section('title', 'Nhập mã xác thực - Portal khách thuê')

@section('content')
    <div class="w-full max-w-sm mx-auto mt-10 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <h1 class="text-lg font-semibold text-gray-900">Nhập mã xác thực</h1>
        <p class="mt-1 text-sm text-gray-500">Mã 6 số vừa được gửi tới số <strong>{{ $phone }}</strong> qua Zalo hoặc SMS — có hiệu lực trong 5 phút.</p>

        <form method="POST" action="{{ route('minihouse.portal.login.verify.submit') }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mã xác thực</label>
                <input
                    type="text"
                    name="code"
                    inputmode="numeric"
                    maxlength="6"
                    placeholder="••••••"
                    required
                    autofocus
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-center text-lg tracking-[0.5em] focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                >
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                Xác nhận
            </button>
        </form>

        <form method="POST" action="{{ route('minihouse.portal.login.request-otp') }}" class="mt-3">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <button type="submit" class="w-full text-sm text-gray-500 hover:text-gray-700 hover:underline py-1">
                Chưa nhận được mã? Gửi lại
            </button>
        </form>

        <a href="{{ route('minihouse.portal.login') }}" class="block mt-2 text-center text-sm text-gray-400 hover:text-gray-600">
            Dùng số điện thoại khác
        </a>
    </div>
@endsection
