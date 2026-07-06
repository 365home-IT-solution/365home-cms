{{-- resources/views/bladethemev1/components/bottom-navigation.blade.php --}}
@php
    $currentUrl = request()->getPathInfo();
@endphp
<style>
    .bottom-navigation-1 {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1000;
    }

    .bottom-nav-1 {
        background: white;
        border-top: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 8px 0 12px 0;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
    }

    .nav-item-1 {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        color: #999;
        transition: color 0.3s ease;
        padding: 4px 8px;
        cursor: pointer;
        text-decoration: none;
    }

    .nav-item-1:hover {
        color: var(--color-primary);
    }

    .nav-item-1.active {
        color: var(--color-primary);
    }

    .nav-icon-1 {
        width: 24px;
        height: 24px;
        margin-bottom: 4px;
        fill: currentColor;
        stroke: currentColor;
        stroke-width: 2;
    }

    .nav-label-1 {
        font-size: 10px;
        font-weight: 500;
        text-align: center;
        white-space: nowrap;
    }

    .nav-item-1.active .nav-icon-1 {
        transform: scale(1.1);
    }

    body {
        padding-bottom: 80px;
    }

    @media (max-width: 480px) {
        .nav-label-1 {
            font-size: 9px;
        }
        .nav-icon-1 {
            width: 20px;
            height: 20px;
        }
    }

    @media (min-width: 1024px) {
        .bottom-navigation-1 {
            display: none;
        }
        body {
            padding-bottom: 0;
        }
    }
</style>

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

        <a href="/tin-tuc" class="nav-item-1 {{ $currentUrl === '/tin-tuc' ? 'active' : '' }}">
            <div class="nav-icon-1">
                <x-heroicon-o-newspaper />
            </div>
            <span class="nav-label-1">Tin tức</span>
        </a>

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
                go() {
                    if (this.isLoggedIn) {
                        window.location.href = '/tai-khoan';
                    } else {
                        window.dispatchEvent(new CustomEvent('open-auth-modal'));
                    }
                },
            }"
            class="contents"
        >
            <a @click.prevent="go()" href="/tai-khoan" class="nav-item-1 {{ $currentUrl === '/tai-khoan' ? 'active' : '' }}">
                <div class="nav-icon-1">
                    <x-heroicon-o-user-circle />
                </div>
                <span class="nav-label-1">Đăng nhập</span>
            </a>
        </div>
    </div>
</div>

