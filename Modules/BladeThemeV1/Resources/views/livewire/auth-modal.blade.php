<div>
@if($enabled)
<div
    x-data="{
        open: false,
        step: 'phone',
        phone: '',
        otp: '',
        fullname: '',
        date_of_birth: '',
        phoneToken: '',
        loading: false,
        error: '',

        init() {
            window.addEventListener('open-auth-modal', () => {
                this.reset();
                this.open = true;
            });
        },

        reset() {
            this.step         = 'phone';
            this.phone        = '';
            this.otp          = '';
            this.fullname     = '';
            this.date_of_birth = '';
            this.phoneToken   = '';
            this.error        = '';
            this.loading      = false;
        },

        async sendOtp() {
            this.loading = true;
            this.error   = '';
            try {
                const res  = await fetch('/api/auth/send-otp', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify({ phone: this.phone }),
                });
                const data = await res.json();
                if (!res.ok) { this.error = data.message; return; }
                this.step = 'otp';
            } catch (e) {
                this.error = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.loading = false;
            }
        },

        async verifyOtp() {
            this.loading = true;
            this.error   = '';
            try {
                const res  = await fetch('/api/auth/verify-otp', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify({ phone: this.phone, otp: this.otp }),
                });
                const data = await res.json();
                if (!res.ok) { this.error = data.message; return; }
                if (data.is_new_user) {
                    this.phoneToken = data.phone_token;
                    this.step       = 'register';
                } else {
                    this.finishAuth(data.token, data.user);
                }
            } catch (e) {
                this.error = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.loading = false;
            }
        },

        async registerUser() {
            this.loading = true;
            this.error   = '';
            try {
                const dob = this.date_of_birth
                    ? this.date_of_birth.split('-').reverse().join('-')
                    : '';
                const res  = await fetch('/api/auth/register', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify({
                        phone_token:   this.phoneToken,
                        fullname:      this.fullname,
                        date_of_birth: dob,
                    }),
                });
                const data = await res.json();
                if (!res.ok) { this.error = data.message; return; }
                this.finishAuth(data.token, data.user);
            } catch (e) {
                this.error = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.loading = false;
            }
        },

        finishAuth(token, user) {
            localStorage.setItem('auth_token', token);
            localStorage.setItem('auth_user', JSON.stringify(user));
            window.dispatchEvent(new CustomEvent('auth-state-changed'));
            this.open = false;
        },
    }"
    @keydown.escape.window="open = false"
    x-cloak
>
    {{-- Backdrop + Dialog --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[9999] flex items-center justify-center px-4"
            style="display: none;"
        >
            {{-- Overlay --}}
            <div
                class="absolute inset-0 bg-black/70 backdrop-blur-sm"
                @click="open = false"
            ></div>

            {{-- Dialog --}}
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-sm rounded-2xl border border-gray-200 shadow-2xl overflow-hidden bg-white"
                @click.stop
            >
                {{-- Nút đóng --}}
                <button
                    @click="open = false"
                    class="absolute top-4 right-4 p-1 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors z-10"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div class="p-6">

                    {{-- BƯỚC 1: Số điện thoại --}}
                    <div x-show="step === 'phone'">
                        <h2 class="text-lg font-semibold text-gray-900 mb-1">Đăng nhập / Đăng ký</h2>
                        <p class="text-sm text-gray-500 mb-5">Nhập số điện thoại để nhận mã OTP qua Zalo.</p>

                        <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Số điện thoại</label>
                            <input
                                x-model="phone"
                                type="tel"
                                placeholder="VD: 0912 345 678"
                                @keydown.enter="sendOtp()"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;"
                            >
                        </div>

                        <div x-show="error" x-cloak class="mb-3 text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="error"></span>
                        </div>

                        <button
                            @click="sendOtp()"
                            :disabled="loading || !phone"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                        >
                            <svg x-show="loading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="loading ? 'Đang gửi...' : 'Gửi mã OTP'"></span>
                        </button>
                    </div>

                    {{-- BƯỚC 2: OTP --}}
                    <div x-show="step === 'otp'">
                        <button @click="step = 'phone'; error = ''" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 mb-4 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                            </svg>
                            Quay lại
                        </button>

                        <h2 class="text-lg font-semibold text-gray-900 mb-1">Nhập mã OTP</h2>
                        <p class="text-sm text-gray-500 mb-5">
                            Mã đã gửi đến Zalo số
                            <span class="font-semibold" style="color: {{ $primaryHex }};" x-text="phone"></span>.
                        </p>

                        <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Mã OTP (6 số)</label>
                            <input
                                x-model="otp"
                                type="text"
                                maxlength="6"
                                placeholder="_ _ _ _ _ _"
                                @keydown.enter="verifyOtp()"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm tracking-[0.5em] text-center bg-gray-50 border border-gray-200 focus:outline-none transition-all"
                            >
                        </div>

                        <div x-show="error" x-cloak class="mb-3 text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="error"></span>
                        </div>

                        <button
                            @click="verifyOtp()"
                            :disabled="loading || otp.length < 6"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                        >
                            <svg x-show="loading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="loading ? 'Đang xác nhận...' : 'Xác nhận OTP'"></span>
                        </button>
                    </div>

                    {{-- BƯỚC 3: Đăng ký --}}
                    <div x-show="step === 'register'">
                        <h2 class="text-lg font-semibold text-gray-900 mb-1">Hoàn tất đăng ký</h2>
                        <p class="text-sm text-gray-500 mb-5">Số điện thoại chưa có tài khoản. Điền thông tin để tạo tài khoản.</p>

                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Họ và tên</label>
                            <input
                                x-model="fullname"
                                type="text"
                                placeholder="Nguyễn Văn A"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-gray-50 border border-gray-200 focus:outline-none transition-all"
                            >
                        </div>

                        <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Ngày sinh</label>
                            <input
                                x-model="date_of_birth"
                                type="date"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-gray-50 border border-gray-200 focus:outline-none transition-all"
                            >
                        </div>

                        <div x-show="error" x-cloak class="mb-3 text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="error"></span>
                        </div>

                        <button
                            @click="registerUser()"
                            :disabled="loading || !fullname || !date_of_birth"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                        >
                            <svg x-show="loading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="loading ? 'Đang tạo tài khoản...' : 'Tạo tài khoản'"></span>
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </template>
</div>
@endif
</div>
