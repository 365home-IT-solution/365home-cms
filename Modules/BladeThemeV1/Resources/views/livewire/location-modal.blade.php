<div
    x-data="{
        open: false,
        loading: false,
        detecting: false,
        provinces: window.__PROVINCES__ || [],
        loaded: Array.isArray(window.__PROVINCES__) && window.__PROVINCES__.length > 0,
        search: '',
        error: '',

        init() {
            this.initProvince();
            // Nút 'Chọn khu vực' ở header (bắn CustomEvent này qua window.dispatchEvent) — LUÔN mở
            // lại được, bất kể đã dismiss trước đó hay chưa, vì đây là hành động chủ động của
            // khách, khác với việc initProvince() tự động mở lúc mới vào site.
            window.addEventListener('open-location-modal', () => {
                this.open = true;
                if (!this.loaded) this.loadProvinces();
            });
        },

        // Đóng popup mà KHÔNG chọn khu vực (bấm nút X, bấm ra ngoài, phím Esc) — đánh dấu đã dismiss
        // vào localStorage để initProvince() không tự mở lại ở các trang sau nữa. Không dùng chung
        // key với home_province_id (vẫn để trống — khác 'đã chọn' với 'đã từ chối chọn'), để nếu
        // sau này có chỗ khác cần phân biệt 2 trạng thái này thì vẫn tách được.
        closePopup() {
            this.open = false;
            if (!localStorage.getItem('home_province_id')) {
                localStorage.setItem('home_location_popup_dismissed', '1');
            }
        },

        // Khách đã đăng nhập và có sẵn province_id trên tài khoản (chọn từ trước, ở thiết bị khác...)
        // thì ưu tiên dùng ngay giá trị đó, không hỏi lại — kể cả khi localStorage của trình duyệt
        // này chưa có/đã khác. Chỉ khi không đăng nhập hoặc tài khoản chưa có province_id mới rơi về
        // hành vi cũ: dựa vào localStorage, thiếu thì mới mở modal hỏi khu vực — trừ khi khách đã
        // từng bấm tắt popup này rồi (home_location_popup_dismissed) thì thôi, không tự mở lại nữa;
        // khách vẫn luôn mở lại được thủ công qua nút 'Chọn khu vực' ở header (xem init() ở trên).
        initProvince() {
            const token = localStorage.getItem('auth_token');
            if (!token) {
                if (!localStorage.getItem('home_province_id') && !localStorage.getItem('home_location_popup_dismissed')) {
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
                    } else if (!localStorage.getItem('home_province_id') && !localStorage.getItem('home_location_popup_dismissed')) {
                        this.open = true;
                        this.loadProvinces();
                    }
                })
                .catch(() => {
                    if (!localStorage.getItem('home_province_id') && !localStorage.getItem('home_location_popup_dismissed')) {
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
            if (this.loaded) return;
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
    @keydown.escape.window="closePopup()"
    x-cloak
>
    <script>
        window.__PROVINCES__ = @json($provinces);
    </script>
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
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="closePopup()"></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                {{-- max-width/max-height dùng px/vh trực tiếp trong style (thay vì class max-w-sm)
                     để sau này chỉnh to/nhỏ cho dễ. Đã tăng từ 384px (max-w-sm cũ) lên 480px, và
                     80vh lên 85vh. --}}
                class="relative w-full rounded-2xl border border-gray-200 shadow-2xl overflow-hidden bg-white flex flex-col"
                style="max-width: 480px; max-height: 85vh;"
                @click.stop
            >
                {{-- Vị trí/kích thước dùng style px trực tiếp (thay vì class top-4/right-4/w-8/h-8)
                     để sau này chỉnh số cho dễ, khỏi tra bảng quy đổi class Tailwind. top:16px,
                     right:16px PHẢI >= bán kính bo góc của card cha (rounded-2xl = 16px) — nếu để
                     dưới 16px, phần bo góc (kết hợp overflow-hidden) sẽ cắt mất 1 phần hình tròn
                     của nút (đúng lỗi giao diện đã gặp trước đó). --}}
                <button
                    type="button"
                    @click="closePopup()"
                    aria-label="Đóng"
                    style="position:absolute; top:3px; right:5px; width:32px; height:32px;"
                    class="flex items-center justify-center rounded-full bg-gray-100 text-gray-500 hover:text-gray-700 hover:bg-gray-200 transition-colors z-10"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <div class="p-6 pb-4 shrink-0">
                    <h2 class="text-lg font-semibold text-gray-900 mb-1 pr-8">Bạn đang ở khu vực nào?</h2>
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
