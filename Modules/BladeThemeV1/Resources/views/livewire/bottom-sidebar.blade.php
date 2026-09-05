{{-- resources/views/bladethemev1/components/bottom-navigation.blade.php --}}
@php
    $currentUrl = request()->getPathInfo();
@endphp

<div class="bottom-navigation-1">
    <div class="bottom-nav-1">
        <a href="/" class="nav-item-1 {{ $currentUrl === '/' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <x-heroicon-o-map />
            </div>
            <span class="nav-label-1">Khám phá</span>
        </a>

        <a href="/yeu-thich" class="nav-item-1 {{ $currentUrl === '/yeu-thich' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <x-heroicon-o-heart />
            </div>
            <span class="nav-label-1">Yêu thích</span>
        </a>

        <a href="/bai-viet" class="nav-item-1 {{ $currentUrl === '/bai-viet' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <x-heroicon-o-newspaper />
            </div>
            <span class="nav-label-1">Bài viết</span>
        </a>

        {{-- Chọn khu vực — bản mobile của nút cùng tên ở header desktop (header-main.blade.php).
             Bắn sự kiện 'open-location-modal' mà location-modal.blade.php đang lắng nghe để mở lại
             popup chọn khu vực, kể cả sau khi khách đã đóng popup lúc mới vào site (đóng thì không
             tự mở lại — xem closePopup() — nhưng bấm nút này ở đây thì luôn mở được). Không dùng
             <a href>: đây là hành động mở popup tại chỗ, không điều hướng trang. --}}
        <button
            type="button"
            x-data="{}"
            @click="window.dispatchEvent(new CustomEvent('open-location-modal'))"
            class="nav-item-1"
            style="background:none; border:none; font-family:inherit;"
        >
            <div class="nav-icon-1">
                <x-heroicon-o-map-pin />
            </div>
            <span class="nav-label-1">Khu vực</span>
        </button>

        <div
            x-data="{
                isLoggedIn: false,
                init() {
                    this.check();
                    window.addEventListener('auth-state-changed', () => this.check());
                },
                check() {
                    this.isLoggedIn = !!(localStorage.getItem('auth_token') && localStorage.getItem('auth_user'));
                },
            }"
            class="contents"
        >
            <a :href="isLoggedIn ? '/tai-khoan#orders-section' : '/ticket-booking'"
               class="nav-item-1 {{ $currentUrl === '/ticket-booking' ? 'active' : '' }}">
                <div class="nav-icon-1">
                    <x-heroicon-o-ticket />
                </div>
                <span class="nav-label-1" x-text="isLoggedIn ? 'Đơn hàng' : 'Tra cứu đơn'"></span>
            </a>
        </div>

        {{-- Chưa đăng nhập: điều hướng sang trang /dang-nhap riêng (không còn mở modal popup —
             xem components/header/actions/auth-button.blade.php, đổi song song ở header desktop). --}}
        <div
            x-data="{
                isLoggedIn: false,
                init() {
                    this.check();
                    window.addEventListener('auth-state-changed', () => this.check());
                },
                check() {
                    this.isLoggedIn = !!(localStorage.getItem('auth_token') && localStorage.getItem('auth_user'));
                },
            }"
            class="contents"
        >
            <a :href="isLoggedIn ? '/tai-khoan' : '{{ route('login.page') }}'" class="nav-item-1 {{ $currentUrl === '/tai-khoan' ? 'active' : '' }}">
                <div class="nav-icon-1">
                    <x-heroicon-o-user-circle />
                </div>
                <span class="nav-label-1" x-text="isLoggedIn ? 'Tài khoản' : 'Đăng nhập'"></span>
            </a>
        </div>
    </div>
</div>

