@extends('minihouse::portal.layout')

@section('title', 'Đăng nhập - Portal khách thuê')

@php
    // 'password' chỉ có lỗi từ form Mật khẩu, 'phone' chỉ có lỗi từ form OTP (xem
    // TenantAuthController::loginWithPassword()/requestOtp()) — không trùng nhau nên đủ để biết nên
    // mở lại đúng tab nào sau khi submit lỗi.
    $activeTab = $errors->has('phone') ? 'otp' : 'password';
@endphp

@section('content')
    <div class="flex flex-col items-center mt-6 sm:mt-12">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white font-bold text-2xl mh-heading" style="background: var(--mh-primary);">M</span>
        <h1 class="mt-4 text-xl font-bold text-gray-900 mh-heading">Đăng nhập Portal khách thuê</h1>
        <p class="mt-1 text-sm text-gray-500">Dùng số điện thoại đã đăng ký khi thuê phòng</p>
    </div>

    <div class="w-full max-w-sm mx-auto mt-6 mh-card mh-card-pad" style="padding: 1.5rem;">
        <div class="grid grid-cols-2 rounded-xl p-1 text-sm font-semibold" style="background: var(--mh-bg);">
            <button type="button" id="tab-btn-password" onclick="minihousePortalShowTab('password')" class="rounded-lg py-2 transition">Mật khẩu</button>
            <button type="button" id="tab-btn-otp" onclick="minihousePortalShowTab('otp')" class="rounded-lg py-2 transition">Mã OTP</button>
        </div>

        <div id="tab-panel-password" class="mt-4">
            <form method="POST" action="{{ route('minihouse.portal.login.password') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="mh-field-label">Số điện thoại</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912345678" required class="mh-input">
                </div>
                <div>
                    <label class="mh-field-label">Mật khẩu</label>
                    <input type="password" name="password" required class="mh-input">
                </div>
                <button type="submit" class="mh-btn-primary">Đăng nhập</button>
                <p class="text-xs text-gray-400 text-center">Chưa đặt mật khẩu? Đăng nhập bằng mã OTP, sau đó vào mục "Mật khẩu" để tự đặt.</p>
            </form>
        </div>

        <div id="tab-panel-otp" class="mt-4">
            <form method="POST" action="{{ route('minihouse.portal.login.request-otp') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="mh-field-label">Số điện thoại</label>
                    <input type="tel" name="phone" value="{{ old('phone') }}" placeholder="0912345678" required class="mh-input">
                </div>
                <button type="submit" class="mh-btn-primary">Gửi mã xác thực</button>
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
                buttons[key].className = 'rounded-lg py-2 transition ' + (key === tab ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500');
            });
        }

        minihousePortalShowTab('{{ $activeTab }}');
    </script>
@endsection
