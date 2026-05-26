@inject('generalSettings', 'App\Settings\GeneralSettings')

@php
    $primaryHex    = $generalSettings->site_theme['primary'] ?? '#FBCB1C';
    $hex           = ltrim($primaryHex, '#');
    $r             = hexdec(substr($hex, 0, 2));
    $g             = hexdec(substr($hex, 2, 2));
    $b             = hexdec(substr($hex, 4, 2));
    $luminance     = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    $textOnPrimary = $luminance > 0.5 ? '#1a1e25' : '#ffffff';
@endphp

<div
    x-data="{
        user: null,
        token: null,

        init() {
            this.loadFromStorage();
            window.addEventListener('auth-state-changed', () => this.loadFromStorage());
        },

        loadFromStorage() {
            const token = localStorage.getItem('auth_token');
            const user  = localStorage.getItem('auth_user');
            if (token && user) {
                try {
                    this.token = token;
                    this.user  = JSON.parse(user);
                } catch (e) {
                    this.clearStorage();
                }
            } else {
                this.token = null;
                this.user  = null;
            }
        },

        openModal() {
            window.dispatchEvent(new CustomEvent('open-auth-modal'));
        },

        logout() {
            if (this.token) {
                fetch('/api/auth/logout', {
                    method:  'POST',
                    headers: {
                        'Authorization': 'Bearer ' + this.token,
                        'Accept': 'application/json',
                    },
                });
            }
            this.clearStorage();
            this.token = null;
            this.user  = null;
            window.dispatchEvent(new CustomEvent('auth-state-changed'));
        },

        clearStorage() {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
        },
    }"
    x-cloak
>
    {{-- Chưa đăng nhập --}}
    <template x-if="!user">
        <button
            @click="openModal()"
            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-opacity duration-200 hover:opacity-85 active:opacity-75 whitespace-nowrap"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
            <span>Đăng nhập</span>
        </button>
    </template>

    {{-- Đã đăng nhập --}}
    <template x-if="user">
        <div class="relative" x-data="{ dropOpen: false }">
            <button
                @click="dropOpen = !dropOpen"
                @click.outside="dropOpen = false"
                style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-opacity duration-200 hover:opacity-85 active:opacity-75"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                </svg>
                <span x-text="user.fullname" class="max-w-[120px] truncate"></span>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="w-3 h-3 transition-transform duration-200"
                    :class="{ 'rotate-180': dropOpen }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>

            <div
                x-show="dropOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="absolute right-0 mt-1.5 w-48 rounded-xl border border-borderGray shadow-xl py-1 z-50"
                style="background-color: #1a1e25;"
            >
                {{-- User name --}}
                <div class="px-4 py-2.5 border-b" style="border-color: #2a2f38;">
                    <p class="text-xs text-gray-500">Đã đăng nhập</p>
                    <p class="text-sm font-semibold text-white truncate mt-0.5" x-text="user.fullname"></p>
                </div>

                {{-- Tài khoản --}}
                <a
                    href="/tai-khoan"
                    @click="dropOpen = false"
                    class="w-full text-left px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white transition-colors duration-150 flex items-center gap-2.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                    </svg>
                    Tài khoản của tôi
                </a>

                {{-- Đơn đặt phòng --}}
                <a
                    href="/tai-khoan"
                    @click="dropOpen = false"
                    class="w-full text-left px-4 py-2.5 text-sm text-gray-300 hover:bg-white/5 hover:text-white transition-colors duration-150 flex items-center gap-2.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>
                    </svg>
                    Đơn đặt phòng
                </a>

                <div class="my-1 border-t" style="border-color: #2a2f38;"></div>

                {{-- Đăng xuất --}}
                <button
                    @click="logout(); dropOpen = false"
                    class="w-full text-left px-4 py-2.5 text-sm text-red-400 hover:bg-white/5 transition-colors duration-150 flex items-center gap-2.5"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                    Đăng xuất
                </button>
            </div>
        </div>
    </template>
</div>
