@extends('minihouse::portal.layout')

@section('title', 'Mật khẩu - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Mật khẩu đăng nhập</h1>
    <p class="mt-1 text-sm text-gray-500">
        @if ($tenant->password)
            Bạn đã đặt mật khẩu — có thể đặt mật khẩu mới trực tiếp bên dưới (không cần nhớ mật khẩu cũ).
        @else
            Đặt mật khẩu để lần sau đăng nhập nhanh, không cần chờ mã OTP qua Zalo/SMS nữa.
        @endif
    </p>

    <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <form method="POST" action="{{ route('minihouse.portal.password.update') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mật khẩu mới</label>
                <input
                    type="password"
                    name="password"
                    required
                    minlength="6"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                >
                <p class="mt-1 text-xs text-gray-400">Tối thiểu 6 ký tự.</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nhập lại mật khẩu mới</label>
                <input
                    type="password"
                    name="password_confirmation"
                    required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                >
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                {{ $tenant->password ? 'Đổi mật khẩu' : 'Đặt mật khẩu' }}
            </button>
        </form>
    </div>
@endsection
