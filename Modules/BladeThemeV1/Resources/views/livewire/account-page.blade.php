<div
    x-data="{
        logout() {
            const token = localStorage.getItem('auth_token');
            if (token) {
                fetch('/api/auth/logout', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                }).catch(() => {});
            }
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
            window.dispatchEvent(new CustomEvent('auth-state-changed'));
            window.location.href = '/';
        },
        openModal() {
            window.dispatchEvent(new CustomEvent('open-auth-modal'));
        },

        // ── Profile Edit ───────────────────────────────────────────────────────
        editOpen: false,
        editLoading: false,
        editError: '',
        editSuccess: '',
        editFullname: '',
        editDob: '',

        // CCCD upload state
        cccdFrontFile: null,
        cccdBackFile: null,
        cccdFrontPreview: null,
        cccdBackPreview: null,
        cccdLoading: false,
        cccdError: '',
        cccdSuccess: '',

        // Change password state
        pwLoading: false,
        pwError: '',
        pwSuccess: '',
        currentPw: '',
        newPw: '',
        newPwConfirm: '',
        showPw: false,

        openEdit() {
            const user = JSON.parse(localStorage.getItem('auth_user') || '{}');
            this.editFullname = user.fullname || '';
            // Convert DOB: 'YYYY-MM-DD' → 'YYYY-MM-DD' (input[type=date] dùng YYYY-MM-DD)
            if (user.date_of_birth) {
                const parts = user.date_of_birth.split('-');
                this.editDob = parts.length === 3 ? parts[0] + '-' + parts[1] + '-' + parts[2] : '';
            } else {
                this.editDob = '';
            }
            this.editError = '';
            this.editSuccess = '';
            this.cccdError = '';
            this.cccdSuccess = '';
            this.cccdFrontFile = null;
            this.cccdBackFile = null;
            this.cccdFrontPreview = null;
            this.cccdBackPreview = null;
            this.pwError = '';
            this.pwSuccess = '';
            this.currentPw = '';
            this.newPw = '';
            this.newPwConfirm = '';
            this.showPw = false;
            this.editOpen = true;
        },

        onCccdPick(event, side) {
            const file = event.target.files[0];
            if (!file) return;
            // Validate type & size (5MB)
            const allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowed.includes(file.type)) {
                this.cccdError = 'Chỉ chấp nhận ảnh JPG, PNG, WEBP.';
                event.target.value = '';
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                this.cccdError = 'Ảnh không được vượt quá 5MB.';
                event.target.value = '';
                return;
            }
            this.cccdError = '';
            const reader = new FileReader();
            reader.onload = (e) => {
                if (side === 'front') { this.cccdFrontPreview = e.target.result; this.cccdFrontFile = file; }
                else                  { this.cccdBackPreview  = e.target.result; this.cccdBackFile  = file; }
            };
            reader.readAsDataURL(file);
        },

        async saveProfile() {
            this.editLoading = true;
            this.editError = '';
            this.editSuccess = '';
            const token = localStorage.getItem('auth_token');
            if (!token) { this.editLoading = false; return; }

            try {
                const fd = new FormData();
                if (this.editFullname.trim()) fd.append('fullname', this.editFullname.trim());
                if (this.editDob) {
                    // Convert YYYY-MM-DD → DD-MM-YYYY for API
                    const [y, m, d] = this.editDob.split('-');
                    fd.append('date_of_birth', d + '-' + m + '-' + y);
                }

                const res  = await fetch('/api/auth/me', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok) { this.editError = data.message || 'Có lỗi xảy ra.'; return; }

                localStorage.setItem('auth_user', JSON.stringify(data));
                this.editSuccess = 'Cập nhật thông tin thành công!';
                window.dispatchEvent(new CustomEvent('auth-state-changed'));
                setTimeout(() => { this.editOpen = false; }, 1200);
            } catch {
                this.editError = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.editLoading = false;
            }
        },

        async changePassword() {
            if (!this.currentPw || !this.newPw || !this.newPwConfirm) {
                this.pwError = 'Vui lòng điền đầy đủ thông tin.';
                return;
            }
            if (this.newPw.length < 8) {
                this.pwError = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
                return;
            }
            if (this.newPw !== this.newPwConfirm) {
                this.pwError = 'Xác nhận mật khẩu không khớp.';
                return;
            }
            this.pwLoading = true;
            this.pwError   = '';
            this.pwSuccess = '';
            const token = localStorage.getItem('auth_token');
            if (!token) { this.pwLoading = false; return; }
            try {
                const res  = await fetch('/api/auth/change-password', {
                    method:  'POST',
                    headers: {
                        'Content-Type':  'application/json',
                        'Authorization': 'Bearer ' + token,
                        'Accept':        'application/json',
                    },
                    body: JSON.stringify({
                        current_password:       this.currentPw,
                        password:               this.newPw,
                        password_confirmation:  this.newPwConfirm,
                    }),
                });
                const data = await res.json();
                if (!res.ok) {
                    this.pwError = data.errors?.current_password?.[0]
                        || data.errors?.password?.[0]
                        || data.message
                        || 'Có lỗi xảy ra.';
                    return;
                }
                this.pwSuccess    = 'Đổi mật khẩu thành công!';
                this.currentPw    = '';
                this.newPw        = '';
                this.newPwConfirm = '';
                setTimeout(() => { this.editOpen = false; }, 1500);
            } catch {
                this.pwError = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.pwLoading = false;
            }
        },

        async saveCccd() {
            if (!this.cccdFrontFile && !this.cccdBackFile) {
                this.cccdError = 'Vui lòng chọn ít nhất một ảnh CCCD.';
                return;
            }
            this.cccdLoading = true;
            this.cccdError   = '';
            this.cccdSuccess = '';
            const token = localStorage.getItem('auth_token');
            if (!token) { this.cccdLoading = false; return; }

            try {
                const fd = new FormData();
                if (this.cccdFrontFile) fd.append('cccd_front', this.cccdFrontFile);
                if (this.cccdBackFile)  fd.append('cccd_back',  this.cccdBackFile);

                const res  = await fetch('/api/auth/me', {
                    method: 'POST',
                    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok) { this.cccdError = data.message || 'Lỗi tải CCCD.'; return; }

                localStorage.setItem('auth_user', JSON.stringify(data));
                this.cccdSuccess = 'Cập nhật CCCD thành công!';
                window.dispatchEvent(new CustomEvent('auth-state-changed'));
                setTimeout(() => { this.editOpen = false; }, 1200);
            } catch {
                this.cccdError = 'Lỗi kết nối. Vui lòng thử lại.';
            } finally {
                this.cccdLoading = false;
            }
        },
    }"
    x-init="
        $nextTick(() => {
            const token = localStorage.getItem('auth_token');
            $wire.loadUserOrders(token || '');
        });
        window.addEventListener('auth-state-changed', () => {
            const t = localStorage.getItem('auth_token');
            $wire.loadUserOrders(t || '');
        });
        window.addEventListener('auth-force-logout', () => {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
            window.dispatchEvent(new CustomEvent('auth-state-changed'));
        });
    "
    class="max-w-screen-md mx-auto px-4 md:px-6 py-10 space-y-5"
>

    {{-- ── INITIAL LOADING ── --}}
    @if($isLoading)
    <div class="flex flex-col items-center justify-center py-24 gap-4">
        <svg class="animate-spin w-8 h-8 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
        </svg>
        <p class="text-sm text-gray-400">Đang tải tài khoản...</p>
    </div>

    {{-- ── NOT LOGGED IN ── --}}
    @elseif(!$isLoggedIn)
    <div class="flex flex-col items-center justify-center min-h-[55vh] text-center py-16">
        <div class="w-20 h-20 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center mb-5 shadow-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-9 h-9 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
            </svg>
        </div>
        <h2 class="text-xl font-bold text-gray-800 mb-2">Bạn chưa đăng nhập</h2>
        <p class="text-gray-500 text-sm mb-8 max-w-xs leading-relaxed">
            Đăng nhập để xem thông tin tài khoản và lịch sử đặt phòng của bạn.
        </p>
        <button
            @click="openModal()"
            class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-semibold shadow-sm transition-opacity hover:opacity-85"
            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
            </svg>
            Đăng nhập ngay
        </button>
    </div>

    {{-- ── LOGGED IN ── --}}
    @else

    {{-- Profile Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="h-20 w-full" style="background: linear-gradient(135deg, {{ $primaryHex }} 0%, {{ $primaryHex }}55 60%, {{ $primaryHex }}11 100%);"></div>

        <div class="px-6 pb-6 -mt-10 flex flex-col sm:flex-row sm:items-end gap-4">
            {{-- Avatar --}}
            <div
                class="w-20 h-20 rounded-2xl flex items-center justify-center text-lg font-bold shrink-0 shadow-md border-4 border-white"
                style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
            >
                @php
                    $parts = array_filter(explode(' ', trim($fullname)));
                    $initials = count($parts) >= 2
                        ? mb_strtoupper(mb_substr($parts[array_key_last($parts) - 1] ?? '', 0, 1) . mb_substr(end($parts), 0, 1))
                        : mb_strtoupper(mb_substr($fullname, 0, 2));
                @endphp
                {{ $initials ?: '?' }}
            </div>

            {{-- Info --}}
            <div class="flex-1 min-w-0 sm:mb-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-xl font-bold text-gray-900 truncate">{{ $fullname }}</h1>
                    <button @click="openEdit()"
                        class="inline-flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-lg border border-gray-200 text-gray-500 hover:text-gray-800 hover:border-gray-300 hover:bg-gray-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                        </svg>
                        Chỉnh sửa
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-3 mt-1.5">
                    @if($phone)
                    <span class="flex items-center gap-1.5 text-sm text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                        </svg>
                        {{ $phone }}
                    </span>
                    @endif
                    @if($dob)
                    <span class="flex items-center gap-1.5 text-sm text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5"/>
                        </svg>
                        {{ \Carbon\Carbon::parse($dob)->format('d/m/Y') }}
                    </span>
                    @endif
                    {{-- CCCD status badge --}}
                    @if($hasCccd)
                    <span class="flex items-center gap-1 text-xs font-medium text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        CCCD đã xác minh
                    </span>
                    @else
                    <button @click="openEdit()"
                        class="flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full hover:bg-amber-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                        Chưa có CCCD
                    </button>
                    @endif
                </div>
            </div>

            {{-- Stats --}}
            <div class="flex items-center gap-5 sm:gap-6 sm:mb-1 shrink-0">
                <div class="text-center">
                    <div class="text-2xl font-bold text-gray-800">{{ $totalOrders }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Đơn đặt</div>
                </div>
                <div class="w-px h-8 bg-gray-200"></div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-emerald-500">{{ $paidOrders }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Đã TT</div>
                </div>
                <div class="w-px h-8 bg-gray-200"></div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-amber-500">{{ $pendingOrders }}</div>
                    <div class="text-xs text-gray-400 mt-0.5">Chờ TT</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Orders Section --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        {{-- Header --}}
        <div class="px-6 py-4 flex items-center justify-between border-b border-gray-100">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background-color: {{ $primaryHex }}22;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" style="color: {{ $primaryHex }};" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>
                    </svg>
                </div>
                <h2 class="text-base font-semibold text-gray-800">Lịch sử đặt phòng</h2>
            </div>
            <span class="text-xs font-medium text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full">
                {{ $totalCount }} đơn
            </span>
        </div>

        {{-- Branch Filter --}}
        @if(count($branches) > 1)
        <div class="px-4 py-2.5 border-b border-gray-100 flex gap-2 overflow-x-auto"
             style="scrollbar-width:none; -ms-overflow-style:none;">
            <button
                wire:click="filterBranch(null)"
                class="shrink-0 px-3 py-1.5 rounded-full text-xs font-medium transition-colors whitespace-nowrap"
                style="{{ $selectedBranchId === null ? 'background-color:' . $primaryHex . '; color:' . $textOnPrimary . ';' : 'background-color:#f3f4f6; color:#6b7280;' }}"
            >Tất cả</button>
            @foreach($branches as $branch)
            <button
                wire:click="filterBranch({{ $branch['id'] }})"
                class="shrink-0 px-3 py-1.5 rounded-full text-xs font-medium transition-colors whitespace-nowrap"
                style="{{ $selectedBranchId === $branch['id'] ? 'background-color:' . $primaryHex . '; color:' . $textOnPrimary . ';' : 'background-color:#f3f4f6; color:#6b7280;' }}"
            >{{ $branch['name'] }}</button>
            @endforeach
        </div>
        @endif

        {{-- Empty state --}}
        @if(count($orders) === 0)
        <div class="py-14 flex flex-col items-center gap-3 text-center px-6">
            <div class="w-16 h-16 rounded-2xl bg-gray-50 border border-gray-100 flex items-center justify-center mb-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                </svg>
            </div>
            <p class="text-gray-700 font-semibold">Chưa có đơn đặt phòng nào</p>
            <p class="text-gray-400 text-sm">Hãy khám phá và đặt phòng ngay hôm nay!</p>
            <a href="/"
               class="mt-3 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold shadow-sm transition-opacity hover:opacity-85"
               style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};">
                Khám phá phòng
            </a>
        </div>

        {{-- Order list --}}
        @else
        <div class="divide-y divide-gray-50">
            @foreach($orders as $order)
            @php
                $statusConfig = match($order['status']) {
                    'paid'               => ['label' => 'Đã thanh toán', 'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
                    'deposit'            => ['label' => 'Đã cọc',        'class' => 'bg-blue-50 text-blue-700 border-blue-200'],
                    'pending'            => ['label' => 'Chờ thanh toán','class' => 'bg-amber-50 text-amber-700 border-amber-200'],
                    'failed'             => ['label' => 'Thất bại',       'class' => 'bg-red-50 text-red-700 border-red-200'],
                    'cancelled_payment'  => ['label' => 'Đã hủy',         'class' => 'bg-gray-100 text-gray-600 border-gray-200'],
                    default              => ['label' => ucfirst($order['status']), 'class' => 'bg-gray-100 text-gray-600 border-gray-200'],
                };
            @endphp
            <div class="p-4 sm:p-5 flex gap-3 sm:gap-4 hover:bg-gray-50/60 transition-colors">

                {{-- Thumbnail --}}
                <div class="w-[72px] h-[72px] sm:w-20 sm:h-20 rounded-xl overflow-hidden shrink-0 bg-gray-100 border border-gray-100">
                    @if($order['thumbnail'])
                        <img src="{{ $order['thumbnail'] }}" alt="{{ $order['room_name'] }}" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center" style="background-color: {{ $primaryHex }}18;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" style="color: {{ $primaryHex }}80;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205l3 1m1.5.5l-1.5-.5M6.75 7.364V3h-3v18m3-13.636l10.5-3.819"/>
                            </svg>
                        </div>
                    @endif
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-900 text-sm leading-snug truncate">{{ $order['room_name'] }}</h3>
                            <p class="text-xs text-gray-400 mt-0.5">Mã: <span class="font-mono font-medium text-gray-600">{{ $order['order_code'] }}</span></p>
                        </div>
                        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $statusConfig['class'] }}">
                            {{ $statusConfig['label'] }}
                        </span>
                    </div>

                    {{-- Dates --}}
                    @if($order['checkin_date'] || $order['checkout_date'])
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5"/>
                        </svg>
                        <span>{{ $order['checkin_date'] ?? '—' }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                        </svg>
                        <span>{{ $order['checkout_date'] ?? '—' }}</span>
                    </div>
                    @endif

                    {{-- Footer --}}
                    <div class="flex items-center justify-between mt-auto pt-0.5">
                        <span class="text-sm font-bold" style="color: {{ $primaryHex }};">
                            {{ number_format($order['amount'], 0, ',', '.') }}đ
                        </span>
                        <a
                            href="/thong-tin-dat-phong/{{ $order['order_code'] }}"
                            class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-800 transition-colors px-2.5 py-1.5 rounded-lg hover:bg-gray-100"
                        >
                            Xem chi tiết
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($totalPages > 1)
        @php $tp = $totalPages; @endphp
        <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-3">
            {{-- Info --}}
            <span class="text-xs text-gray-400">
                {{ ($currentPage - 1) * $perPage + 1 }}–{{ min($currentPage * $perPage, $totalCount) }}
                / {{ $totalCount }} đơn
            </span>

            {{-- Controls --}}
            <div class="flex items-center gap-1">
                {{-- Prev --}}
                <button
                    wire:click="goToPage({{ $currentPage - 1 }})"
                    @class(['p-1.5 rounded-lg text-gray-400 transition-colors', 'opacity-30 cursor-not-allowed' => $currentPage === 1, 'hover:bg-gray-100 hover:text-gray-700' => $currentPage > 1])
                    @disabled($currentPage === 1)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                    </svg>
                </button>

                {{-- Page numbers --}}
                @for($p = 1; $p <= $tp; $p++)
                    @if($p === 1 || $p === $tp || abs($p - $currentPage) <= 1)
                    <button
                        wire:click="goToPage({{ $p }})"
                        class="min-w-[30px] h-[30px] rounded-lg text-xs font-medium transition-colors"
                        style="{{ $p === $currentPage ? 'background-color:' . $primaryHex . '; color:' . $textOnPrimary . ';' : 'color:#6b7280;' }}"
                        @class(['hover:bg-gray-100' => $p !== $currentPage])
                    >{{ $p }}</button>
                    @elseif(abs($p - $currentPage) === 2)
                    <span class="text-xs text-gray-300 px-0.5">…</span>
                    @endif
                @endfor

                {{-- Next --}}
                <button
                    wire:click="goToPage({{ $currentPage + 1 }})"
                    @class(['p-1.5 rounded-lg text-gray-400 transition-colors', 'opacity-30 cursor-not-allowed' => $currentPage === $tp, 'hover:bg-gray-100 hover:text-gray-700' => $currentPage < $tp])
                    @disabled($currentPage === $tp)
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                    </svg>
                </button>
            </div>
        </div>
        @endif
        @endif
    </div>

    {{-- Quick Actions --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <a href="/"
           class="flex items-center gap-4 p-4 bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background-color: {{ $primaryHex }}18;">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" style="color: {{ $primaryHex }};" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205l3 1m1.5.5l-1.5-.5M6.75 7.364V3h-3v18m3-13.636l10.5-3.819"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-800">Đặt phòng mới</p>
                <p class="text-xs text-gray-400 mt-0.5">Khám phá các phòng hiện có</p>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-300 ml-auto shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
        </a>

        <button
            @click="logout()"
            class="flex items-center gap-4 p-4 bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md hover:border-red-100 transition-all text-left cursor-pointer"
        >
            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-red-50">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-semibold text-red-500">Đăng xuất</p>
                <p class="text-xs text-gray-400 mt-0.5">Thoát khỏi tài khoản</p>
            </div>
        </button>
    </div>

    {{-- ── EDIT PROFILE MODAL ── --}}
    <template x-teleport="body">
        <div
            x-show="editOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[9999] flex items-center justify-center px-4 py-6"
            style="display:none;"
            @keydown.escape.window="editOpen = false"
        >
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="editOpen = false"></div>

            {{-- Dialog --}}
            <div
                x-show="editOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl overflow-hidden"
                @click.stop
            >
                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Chỉnh sửa hồ sơ</h2>
                    <button @click="editOpen = false"
                        class="p-1.5 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-5 space-y-5 overflow-y-auto max-h-[70vh]">

                    {{-- Thông tin cơ bản --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Thông tin cá nhân</h3>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Họ và tên</label>
                            <input x-model="editFullname" type="text" placeholder="Nguyễn Văn A"
                                class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;">
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Ngày sinh</label>
                            <input x-model="editDob" type="date"
                                class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;">
                        </div>

                        <div x-show="editError" x-cloak class="text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="editError"></span>
                        </div>
                        <div x-show="editSuccess" x-cloak class="text-sm text-green-600 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span x-text="editSuccess"></span>
                        </div>

                        <button @click="saveProfile()" :disabled="editLoading"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};">
                            <svg x-show="editLoading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="editLoading ? 'Đang lưu...' : 'Lưu thông tin'"></span>
                        </button>
                    </div>

                    <hr class="border-gray-100">

                    {{-- CCCD Upload --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide flex items-center gap-2">
                            Căn cước công dân
                            @if($hasCccd)
                            <span class="text-xs font-medium text-green-700 bg-green-50 border border-green-200 px-2 py-0.5 rounded-full">Đã có</span>
                            @else
                            <span class="text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full">Chưa có</span>
                            @endif
                        </h3>

                        @if($hasCccd)
                        <div class="flex gap-2">
                            <div class="flex-1 rounded-xl overflow-hidden border border-gray-200 aspect-video">
                                <img src="{{ $cccdFrontUrl }}" alt="Mặt trước" class="w-full h-full object-cover">
                            </div>
                            <div class="flex-1 rounded-xl overflow-hidden border border-gray-200 aspect-video">
                                <img src="{{ $cccdBackUrl }}" alt="Mặt sau" class="w-full h-full object-cover">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500">Muốn cập nhật CCCD? Chọn ảnh mới bên dưới.</p>
                        @endif

                        {{-- Upload areas --}}
                        <div class="grid grid-cols-2 gap-3">
                            @foreach(['front' => 'Mặt trước', 'back' => 'Mặt sau'] as $side => $label)
                            <label class="relative flex flex-col items-center justify-center h-28 rounded-xl border-2 border-dashed border-gray-300 cursor-pointer overflow-hidden hover:border-gray-400 hover:bg-gray-50 transition-all">
                                <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp"
                                    @change="onCccdPick($event, '{{ $side }}')">

                                {{-- Preview --}}
                                <template x-if="{{ $side === 'front' ? 'cccdFrontPreview' : 'cccdBackPreview' }}">
                                    <img :src="{{ $side === 'front' ? 'cccdFrontPreview' : 'cccdBackPreview' }}"
                                        class="absolute inset-0 w-full h-full object-cover rounded-xl">
                                </template>

                                {{-- Placeholder --}}
                                <template x-if="!{{ $side === 'front' ? 'cccdFrontPreview' : 'cccdBackPreview' }}">
                                    <div class="flex flex-col items-center gap-1 text-gray-400 pointer-events-none">
                                        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                        </svg>
                                        <span class="text-xs font-medium">{{ $label }}</span>
                                    </div>
                                </template>

                                {{-- Hover overlay when has preview --}}
                                <template x-if="{{ $side === 'front' ? 'cccdFrontPreview' : 'cccdBackPreview' }}">
                                    <div class="absolute inset-0 bg-black/30 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity">
                                        <span class="text-white text-xs font-semibold">Đổi ảnh</span>
                                    </div>
                                </template>
                            </label>
                            @endforeach
                        </div>

                        <div x-show="cccdError" x-cloak class="text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="cccdError"></span>
                        </div>
                        <div x-show="cccdSuccess" x-cloak class="text-sm text-green-600 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span x-text="cccdSuccess"></span>
                        </div>

                        <p class="text-xs text-gray-400 leading-relaxed">
                            * Ảnh CCCD phải rõ nét, có mã QR đọc được. Chấp nhận JPG, PNG, WEBP (tối đa 5MB mỗi ảnh).
                        </p>

                        <button @click="saveCccd()" :disabled="cccdLoading || (!cccdFrontFile && !cccdBackFile)"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 disabled:opacity-40 disabled:cursor-not-allowed border-2"
                            style="border-color: {{ $primaryHex }}; color: {{ $primaryHex }};">
                            <svg x-show="cccdLoading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="cccdLoading ? 'Đang tải lên...' : 'Cập nhật CCCD'"></span>
                        </button>
                    </div>

                    <hr class="border-gray-100">

                    {{-- Đổi mật khẩu --}}
                    <div class="space-y-3">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide flex items-center gap-2">
                            Đổi mật khẩu
                            <span class="text-xs font-normal text-gray-400 normal-case">(không bắt buộc)</span>
                        </h3>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Mật khẩu hiện tại</label>
                            <div class="relative">
                                <input x-model="currentPw" :type="showPw ? 'text' : 'password'"
                                    placeholder="Mật khẩu hiện tại"
                                    class="w-full rounded-lg px-3 py-2.5 pr-10 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                    style="--tw-ring-color: {{ $primaryHex }}40;">
                                <button type="button" @click="showPw = !showPw"
                                    class="absolute inset-y-0 right-3 flex items-center text-gray-400 hover:text-gray-600">
                                    <svg x-show="!showPw" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <svg x-show="showPw" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Mật khẩu mới</label>
                            <input x-model="newPw" :type="showPw ? 'text' : 'password'"
                                placeholder="Ít nhất 8 ký tự"
                                class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;">
                        </div>

                        <div x-show="newPw" x-cloak>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Xác nhận mật khẩu mới</label>
                            <input x-model="newPwConfirm" :type="showPw ? 'text' : 'password'"
                                placeholder="Nhập lại mật khẩu mới"
                                class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                                style="--tw-ring-color: {{ $primaryHex }}40;">
                        </div>

                        <div x-show="pwError" x-cloak class="text-sm text-red-500 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span x-text="pwError"></span>
                        </div>
                        <div x-show="pwSuccess" x-cloak class="text-sm text-green-600 flex items-center gap-1.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span x-text="pwSuccess"></span>
                        </div>

                        <button @click="changePassword()" :disabled="pwLoading || !currentPw || !newPw"
                            class="w-full rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 disabled:opacity-40 disabled:cursor-not-allowed border-2"
                            style="border-color: {{ $primaryHex }}; color: {{ $primaryHex }};">
                            <svg x-show="pwLoading" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            <span x-text="pwLoading ? 'Đang xử lý...' : 'Đổi mật khẩu'"></span>
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </template>

    @endif
</div>
