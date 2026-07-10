<div
    x-data="{
        open: false,
        loading: false,
        detecting: false,
        provinces: [],
        loaded: false,
        search: '',
        error: '',

        init() {
            this.initProvince();
            window.addEventListener('open-location-modal', () => {
                this.open = true;
                if (!this.loaded) this.loadProvinces();
            });
        },

        // Khách đã đăng nhập và có sẵn province_id trên tài khoản (chọn từ trước, ở thiết bị khác...)
        // thì ưu tiên dùng ngay giá trị đó, không hỏi lại — kể cả khi localStorage của trình duyệt
        // này chưa có/đã khác. Chỉ khi không đăng nhập hoặc tài khoản chưa có province_id mới rơi về
        // hành vi cũ: dựa vào localStorage, thiếu thì mới mở modal hỏi khu vực.
        initProvince() {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                if (!localStorage.getItem('home_province_id')) {
                    this.open = true;
                    this.loadProvinces();
                }
                return;
            }

            fetch('/api/v1/provinces/select', {
                headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token },
            })
                .then(res => (res.status === 204 ? null : res.json()))
                .then(data => {
                    if (data && data.id) {
                        localStorage.setItem('home_province_id', data.id);
                        localStorage.setItem('home_province_name', data.name);
                        window.dispatchEvent(new CustomEvent('province-selected', { detail: data }));
                    } else if (!localStorage.getItem('home_province_id')) {
                        this.open = true;
                        this.loadProvinces();
                    }
                })
                .catch(() => {
                    if (!localStorage.getItem('home_province_id')) {
                        this.open = true;
                        this.loadProvinces();
                    }
                });
        },

        get filteredProvinces() {
            if (!this.search) return this.provinces;
            const q = this.search.toLowerCase();
            return this.provinces.filter(p => p.name.toLowerCase().includes(q));
        },

        loadProvinces() {
            this.loading = true;
            this.error = '';
            fetch('/api/v1/provinces', { headers: { 'Accept': 'application/json' } })
                .then(res => res.json())
                .then(data => {
                    this.provinces = data.provinces || [];
                    this.loaded = true;
                })
                .catch(() => { this.error = 'Không tải được danh sách tỉnh/thành.'; })
                .finally(() => { this.loading = false; });
        },

        selectProvince(p) {
            localStorage.setItem('home_province_id', p.id);
            localStorage.setItem('home_province_name', p.name);
            window.dispatchEvent(new CustomEvent('province-selected', { detail: p }));
            this.open = false;
            this.persistSelection(p.id);
        },

        persistSelection(provinceId) {
            const token = localStorage.getItem('auth_token');
            const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            const body = { province_id: provinceId };

            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            } else {
                let deviceToken = localStorage.getItem('guest_device_token');
                if (!deviceToken) {
                    deviceToken = (window.crypto && crypto.randomUUID)
                        ? crypto.randomUUID()
                        : Date.now() + '-' + Math.random().toString(36).slice(2);
                    localStorage.setItem('guest_device_token', deviceToken);
                }
                body.device_token = deviceToken;
            }

            fetch('/api/v1/provinces/select', {
                method: 'POST',
                headers,
                body: JSON.stringify(body),
            }).catch(() => {});
        },

        detectLocation() {
            if (!navigator.geolocation) {
                this.error = 'Trình duyệt không hỗ trợ định vị.';
                return;
            }
            this.detecting = true;
            this.error = '';
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    fetch('/api/v1/provinces/detect?lat=' + pos.coords.latitude + '&lng=' + pos.coords.longitude, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.province) this.selectProvince(data.province);
                            else this.error = 'Không xác định được vị trí gần bạn.';
                        })
                        .catch(() => { this.error = 'Không tải được vị trí.'; })
                        .finally(() => { this.detecting = false; });
                },
                () => {
                    this.error = 'Bạn chưa cho phép truy cập vị trí.';
                    this.detecting = false;
                }
            );
        },
    }"
    @keydown.escape.window="open = false"
    x-cloak
>
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
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="open = false"></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative w-full max-w-sm rounded-2xl border border-gray-200 shadow-2xl overflow-hidden bg-white flex flex-col"
                style="max-height: 80vh;"
                @click.stop
            >
                <button
                    @click="open = false"
                    class="absolute top-4 right-4 p-1 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors z-10"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div class="p-6 pb-4 shrink-0">
                    <h2 class="text-lg font-semibold text-gray-900 mb-1">Bạn đang ở khu vực nào?</h2>
                    <p class="text-sm text-gray-500 mb-4">Chọn khu vực để xem đầy đủ phòng và ưu đãi gần bạn.</p>

                    <button
                        type="button"
                        @click="detectLocation()"
                        :disabled="detecting"
                        class="w-full mb-3 rounded-lg py-2.5 text-sm font-semibold flex items-center justify-center gap-2 transition-opacity hover:opacity-85 active:opacity-75 disabled:opacity-50 disabled:cursor-not-allowed"
                        style="background-color: {{ $primaryHex }}; color: {{ $textOnPrimary }};"
                    >
                        <svg x-show="detecting" class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <svg x-show="!detecting" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span x-text="detecting ? 'Đang định vị...' : 'Dùng vị trí hiện tại'"></span>
                    </button>

                    <input
                        x-model="search"
                        type="text"
                        placeholder="Tìm tỉnh/thành..."
                        class="w-full rounded-lg px-3 py-2.5 text-sm text-gray-900 bg-gray-50 border border-gray-200 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                        style="--tw-ring-color: {{ $primaryHex }}40;"
                    >

                    <div x-show="error" x-cloak class="mt-3 text-sm text-red-500 flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        <span x-text="error"></span>
                    </div>
                </div>

                <div class="overflow-y-auto px-3 pb-4" style="flex: 1 1 auto;">
                    <template x-if="loading">
                        <p class="text-center text-sm text-gray-400 py-6">Đang tải danh sách...</p>
                    </template>

                    <template x-if="!loading && loaded && !filteredProvinces.length">
                        <p class="text-center text-sm text-gray-400 py-6">Không tìm thấy tỉnh/thành phù hợp.</p>
                    </template>

                    <template x-for="province in filteredProvinces" :key="province.id">
                        <button
                            type="button"
                            @click="selectProvince(province)"
                            class="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-left text-sm text-gray-800 hover:bg-gray-50 transition-colors"
                        >
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span x-text="province.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
