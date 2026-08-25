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
        password: '',
        password_confirmation: '',
        showPassword: false,
        phoneToken: '',
        loading: false,
        error: '',
        loginMode: 'otp',
        loginPassword: '',
        showLoginPassword: false,

        init() {
            window.addEventListener('open-auth-modal', () => {
                this.reset();
                this.open = true;
            });
        },

        reset() {
            this.step                 = 'phone';
            this.phone                = '';
            this.otp                  = '';
            this.fullname             = '';
            this.date_of_birth        = '';
            this.password             = '';
            this.password_confirmation = '';
            this.showPassword         = false;
            this.phoneToken           = '';
            this.error                = '';
            this.loading              = false;
            this.loginMode            = 'otp';
            this.loginPassword        = '';
            this.showLoginPassword    = false;
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

        async loginWithPassword() {
            this.loading = true;
            this.error   = '';
            try {
                const res  = await fetch('/api/auth/login', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify({ phone: this.phone, password: this.loginPassword }),
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
            // Validate password nếu có nhập
            if (this.password) {
                if (this.password.length < 8) {
                    this.error   = 'Mật khẩu phải có ít nhất 8 ký tự.';
                    this.loading = false;
                    return;
                }
                if (this.password !== this.password_confirmation) {
                    this.error   = 'Xác nhận mật khẩu không khớp.';
                    this.loading = false;
                    return;
                }
            }
            try {
                const dob = this.date_of_birth
                    ? this.date_of_birth.split('-').reverse().join('-')
                    : '';
                const payload = {
                    phone_token:   this.phoneToken,
                    fullname:      this.fullname,
                    date_of_birth: dob,
                };
                if (this.password) {
                    payload.password              = this.password;
                    payload.password_confirmation = this.password_confirmation;
                }
                const res  = await fetch('/api/auth/register', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify(payload),
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
                class="relative w-full max-w-3xl rounded-2xl border border-gray-200 shadow-2xl overflow-hidden bg-white"
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

                {{-- 2 cột từ md trở lên: cột 1 hình ảnh minh hoạ (voucher.png), cột 2 toàn bộ form
                     (cả 3 bước phone/otp/register) — mobile chỉ hiện cột form, ẩn hẳn cột ảnh cho
                     đỡ chật. --}}
                <div class="grid md:grid-cols-2">
                    <div class="hidden md:block" style="background-image:url('{{ asset('images/voucher.png') }}'); background-size:cover; background-position:center;"></div>

                <div class="p-6 md:p-8">

                    {{-- BƯỚC 1: Số điện thoại — giao diện tham khảo kiểu Go2Joy: lời chào lớn +
                         phụ đề, ô nhập bo tròn rộng rãi, nút hành động chính to dạng pill. Luồng
                         hoạt động GIỮ NGUYÊN như cũ (OTP gửi qua Zalo / đăng nhập mật khẩu) — chỉ
                         đổi giao diện, không có đăng nhập Zalo kiểu OAuth (chưa có backend). Bỏ
                         tab chuyển đổi OTP/Mật khẩu (segmented control) — thay bằng 1 dòng chữ
                         gạch dưới bên dưới nút chính để đổi chế độ, gọn hơn. --}}
                    <div x-show="step === 'phone'">
                        {{-- pr-8: chừa chỗ cho nút đóng (×) absolute top-4 right-4 — không có
                             padding này thì tiêu đề/phụ đề dài tràn ra hết bề ngang, ở mobile chữ
                             xuống dòng ngay dưới/đè lên nút đóng (đúng lỗi đã gặp). --}}
                        <h2 class="text-2xl font-bold text-gray-900 mb-1 pr-8">365 Home xin chào!</h2>
                        <p class="text-sm text-gray-500 mb-5 pr-8">Đăng nhập để đặt phòng với những ưu đãi độc quyền dành cho thành viên.</p>

                        <div class="mb-4">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Số điện thoại</label>
                            <input
                                x-model="phone"
                                type="tel"
                                placeholder="VD: 0912 345 678"
                                @keydown.enter="loginMode === 'otp' ? sendOtp() : loginWithPassword()"
                                class="w-full rounded-xl px-4 py-3.5 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;"
                            >
                        </div>

                        {{-- Password field — chỉ hiện khi loginMode === 'password' --}}
                        <div class="mb-4" x-show="loginMode === 'password'" x-cloak>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Mật khẩu</label>
                            <div class="relative">
                                <input
                                    x-model="loginPassword"
                                    :type="showLoginPassword ? 'text' : 'password'"
                                    placeholder="Nhập mật khẩu"
                                    @keydown.enter="loginWithPassword()"
                                    class="w-full rounded-xl px-4 py-3.5 pr-10 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                    style="--tw-ring-color: {{ $primaryHex }}40;"
                                >
                                <button type="button" @click="showLoginPassword = !showLoginPassword"
                                    class="absolute inset-y-0 right-3 flex items-center text-gray-400 hover:text-gray-600">
                                    <svg x-show="!showLoginPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <svg x-show="showLoginPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Gợi ý kênh gửi OTP (thay cho icon Zalo từng nằm trên tab) — chỉ hiện ở
                             chế độ OTP. --}}
                        <p x-show="loginMode === 'otp'" x-cloak class="flex items-center gap-1.5 text-xs text-gray-400 mb-4 -mt-1.5">
                            <img src="{{ asset('images/zalo.png') }}" alt="" style="width:14px;height:14px;flex-shrink:0;object-fit:contain;">
                            Mã xác thực sẽ được gửi qua Zalo
                        </p>

                        <div x-show="error" x-cloak class="mb-3 text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="error"></span>
                        </div>

                        <button
                            @click="loginMode === 'otp' ? sendOtp() : loginWithPassword()"
                            :disabled="loading || !phone || (loginMode === 'password' && !loginPassword)"
                            class="w-full rounded-lg py-3.5 text-sm font-bold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                        >
                            <svg x-show="loading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="loading ? (loginMode === 'otp' ? 'Đang gửi...' : 'Đang đăng nhập...') : (loginMode === 'otp' ? 'Gửi mã OTP' : 'Đăng nhập')"></span>
                        </button>

                        {{-- Đổi chế độ đăng nhập — thay cho tab segmented control cũ, chỉ 1 dòng
                             chữ gạch dưới. --}}
                        <p class="text-center text-sm mt-4">
                            <button type="button" x-show="loginMode === 'otp'" x-cloak
                                @click="loginMode = 'password'; error = ''"
                                class="font-semibold underline" style="color: {{ $primaryHex }};"
                            >Đăng nhập bằng mật khẩu</button>
                            <button type="button" x-show="loginMode === 'password'" x-cloak
                                @click="loginMode = 'otp'; error = ''"
                                class="font-semibold underline" style="color: {{ $primaryHex }};"
                            >Đăng nhập bằng OTP qua Zalo</button>
                        </p>
                    </div>

                    {{-- BƯỚC 2: OTP --}}
                    <div x-show="step === 'otp'">
                        <button @click="step = 'phone'; error = ''" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 mb-4 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                            </svg>
                            Quay lại
                        </button>

                        <h2 class="text-lg font-semibold text-gray-900 mb-1 pr-8">Nhập mã OTP</h2>
                        <p class="text-sm text-gray-500 mb-5 pr-8">
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
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm tracking-[0.5em] text-center bg-white border border-gray-200 focus:outline-none transition-all"
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
                            class="w-full rounded-lg py-3.5 text-sm font-bold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
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
                        <h2 class="text-lg font-semibold text-gray-900 mb-1 pr-8">Hoàn tất đăng ký</h2>
                        <p class="text-sm text-gray-500 mb-5 pr-8">Số điện thoại chưa có tài khoản. Điền thông tin để tạo tài khoản.</p>

                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Họ và tên</label>
                            <input
                                x-model="fullname"
                                type="text"
                                placeholder="Nguyễn Văn A"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none transition-all"
                            >
                        </div>

                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Ngày sinh</label>
                            <input
                                x-model="date_of_birth"
                                type="date"
                                class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none transition-all"
                            >
                        </div>

                        {{-- Mật khẩu (tuỳ chọn) --}}
                        <details class="mb-4 group">
                            <summary class="flex items-center gap-1.5 text-xs font-medium text-gray-500 uppercase tracking-wide cursor-pointer select-none list-none">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                                Đặt mật khẩu (không bắt buộc)
                            </summary>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Mật khẩu</label>
                                    <div class="relative">
                                        <input
                                            x-model="password"
                                            :type="showPassword ? 'text' : 'password'"
                                            placeholder="Ít nhất 8 ký tự"
                                            class="w-full rounded-lg px-3 py-2.5 pr-10 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none transition-all"
                                        >
                                        <button type="button" @click="showPassword = !showPassword" class="absolute inset-y-0 right-3 flex items-center text-gray-400 hover:text-gray-600">
                                            <svg x-show="!showPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            </svg>
                                            <svg x-show="showPassword" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                <div x-show="password" x-cloak>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5 uppercase tracking-wide">Xác nhận mật khẩu</label>
                                    <input
                                        x-model="password_confirmation"
                                        :type="showPassword ? 'text' : 'password'"
                                        placeholder="Nhập lại mật khẩu"
                                        class="w-full rounded-lg px-3 py-2.5 text-gray-900 text-sm bg-white border border-gray-200 focus:outline-none transition-all"
                                    >
                                </div>
                                <p class="text-xs text-gray-400">Đặt mật khẩu để có thể đăng nhập bằng SĐT + mật khẩu sau này.</p>
                            </div>
                        </details>

                        <div x-show="error" x-cloak class="mb-3 text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="error"></span>
                        </div>

                        <button
                            @click="registerUser()"
                            :disabled="loading || !fullname || !date_of_birth"
                            class="w-full rounded-lg py-3.5 text-sm font-bold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
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
        </div>
    </template>
</div>
@endif
</div>
