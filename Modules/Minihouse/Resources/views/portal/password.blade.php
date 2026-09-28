@extends('minihouse::portal.layout')

@section('title', 'Mật khẩu - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Mật khẩu đăng nhập</h1>
    <p class="mt-1 text-sm text-gray-500">
        @if ($tenant->password)
            Bạn đã đặt mật khẩu — có thể đặt mật khẩu mới trực tiếp bên dưới (không cần nhớ mật khẩu cũ).
        @else
            Đặt mật khẩu để lần sau đăng nhập nhanh, không cần chờ mã OTP qua Zalo/SMS nữa.
        @endif
    </p>

    <div class="mt-4 mh-card mh-card-pad">
        <form method="POST" action="{{ route('minihouse.portal.password.update') }}" class="space-y-4">
            @csrf

            <div>
                <label class="mh-field-label">Mật khẩu mới</label>
                <input type="password" name="password" required minlength="6" class="mh-input">
                <p class="mt-1 text-xs text-gray-400">Tối thiểu 6 ký tự.</p>
            </div>

            <div>
                <label class="mh-field-label">Nhập lại mật khẩu mới</label>
                <input type="password" name="password_confirmation" required class="mh-input">
            </div>

            <button type="submit" class="mh-btn-primary">{{ $tenant->password ? 'Đổi mật khẩu' : 'Đặt mật khẩu' }}</button>
        </form>
    </div>
@endsection
