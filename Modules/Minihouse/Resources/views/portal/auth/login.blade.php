@extends('minihouse::portal.layout')

@section('title', 'Đăng nhập - Portal khách thuê')

@php
    // 'password' chỉ có lỗi từ form Mật khẩu, 'phone' chỉ có lỗi từ form OTP (xem
    // TenantAuthController::loginWithPassword()/requestOtp()) — không trùng nhau nên đủ để biết nên
    // mở lại đúng tab nào sau khi submit lỗi.
    $activeTab = $errors->has('phone') ? 'otp' : 'password';
@endphp

@section('content')
    <div class="w-full max-w-sm mx-auto mt-10 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <h1 class="text-lg font-semibold text-gray-900">Đăng nhập</h1>
        <p class="mt-1 text-sm text-gray-500">Dùng số điện thoại đã đăng ký khi thuê phòng.</p>

        <div class="mt-4 grid grid-cols-2 rounded-lg bg-gray-100 p-1 text-sm font-medium">
            <button type="button" id="tab-btn-password" onclick="minihousePortalShowTab('password')" class="rounded-md py-1.5 transition">Mật khẩu</button>
            <button type="button" id="tab-btn-otp" onclick="minihousePortalShowTab('otp')" class="rounded-md py-1.5 transition">Mã OTP</button>
        </div>

        <div id="tab-panel-password" class="mt-4">
            <form method="POST" action="{{ route('minihouse.portal.login.password') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
                    <input
                        type="tel"
                        name="phone"
                        value="{{ old('phone') }}"
                        placeholder="0912345678"
                        required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mật khẩu</label>
                    <input
                        type="password"
                        name="password"
                        required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                    >
                </div>
                <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                    Đăng nhập
                </button>
                <p class="text-xs text-gray-400 text-center">Chưa đặt mật khẩu? Đăng nhập bằng mã OTP, sau đó vào mục "Mật khẩu" để tự đặt.</p>
            </form>
        </div>

        <div id="tab-panel-otp" class="mt-4">
            <form method="POST" action="{{ route('minihouse.portal.login.request-otp') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
                    <input
                        type="tel"
                        name="phone"
                        value="{{ old('phone') }}"
                        placeholder="0912345678"
                        required
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10"
                    >
                </div>
                <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                    Gửi mã xác thực
                </button>
                <p class="text-xs text-gray-400 text-center">Hệ thống sẽ gửi mã qua Zalo (hoặc SMS) tới số điện thoại này.</p>
            </form>
        </div>
    </div>

    <script>
        function minihousePortalShowTab(tab) {
            var panels = { password: document.getElementById('tab-panel-password'), otp: document.getElementById('tab-panel-otp') };
            var buttons = { password: document.getElementById('tab-btn-password'), otp: document.getElementById('tab-btn-otp') };

            Object.keys(panels).forEach(function (key) {
                panels[key].hidden = key !== tab;
                buttons[key].className = 'rounded-md py-1.5 transition ' + (key === tab ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500');
            });
        }

        minihousePortalShowTab('{{ $activeTab }}');
    </script>
@endsection
