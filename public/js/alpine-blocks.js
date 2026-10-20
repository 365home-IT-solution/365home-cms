// Build bản .min (script thường, chỉ minify khoảng trắng — xem ghi chú bên dưới về `with`):
//   node_modules/.bin/esbuild --minify-whitespace --loader=js < public/js/alpine-blocks.js > public/js/alpine-blocks.min.js
// Các khối x-data / x-init dài của Alpine, chuyển từ thuộc tính HTML trong Blade sang đây để HTML
// trang không phải mang theo hàng chục KB code ở MỖI lượt tải (file này được trình duyệt cache).
// Trong Blade chỉ còn: x-data="xBlock('tên', $el, {tham số})".
//
// Code từng khối được giữ NGUYÊN VĂN như lúc còn nằm trong thuộc tính. Để hành vi không đổi, mỗi khối
// chạy trong with(magics){ with(scope){ ... } } — đúng cách Alpine tự chạy biểu thức inline: định
// danh trần (biến của x-data cha, $refs, $watch, $wire...) vẫn phân giải y như cũ. Vì dùng `with`,
// file này phải là script thường (không module, không 'use strict') và chỉ được minify khoảng trắng.
//
// KHÔNG gõ dấu ngoặc kép trong code các khối đang là x-data cũ nếu sau này cần đưa ngược về Blade.
// <template> lớn lặp lại y hệt bị MinifyHtml bỏ nội dung ở các lần sau (data-tpl-ref, xem
// MinifyHtml::dedupeTemplates()) — chép lại nội dung từ bản gốc (data-tpl-src) ngay khi file này chạy:
// defer => DOM đã parse xong, và luôn chạy TRƯỚC livewire.js (Alpine) vì đứng trước trong <head>.
(function () {
    document.querySelectorAll('template[data-tpl-ref]').forEach(function (ref) {
        if (ref.content.childNodes.length) return;
        var src = document.querySelector('template[data-tpl-src="' + ref.getAttribute('data-tpl-ref') + '"]');
        if (src) ref.content.appendChild(src.content.cloneNode(true));
    });
})();

// Trang chủ desktop: pill gọn trên header thuộc khung tìm kiếm đè banner (homeOverlay, xem
// HeroSection::$homeOverlay) — bấm thì cuộn mượt lên khung đó (chừa chỗ header dính phía trên) rồi
// mở đúng ô (loc/date/guests) qua 'hero-open-field' mà khung đó đang nghe. Đợi cuộn xong mới mở để
// popup lịch (openDateDropdown) đo vị trí đúng chỗ.
window.__heroOverlayOpen = function (field) {
    var overlay = document.getElementById('hero-banner-search-overlay');
    if (!overlay) return;
    var header = document.getElementById('main-header-bar');
    var offset = (header ? header.offsetHeight : 80) + 24;
    var top = Math.max(0, overlay.getBoundingClientRect().top + window.scrollY - offset);
    var done = false;
    var open = function () {
        if (done) return;
        done = true;
        window.removeEventListener('scrollend', open);
        if (field) window.dispatchEvent(new CustomEvent('hero-open-field', { detail: field }));
    };
    if (Math.abs(window.scrollY - top) < 2) { open(); return; }
    window.addEventListener('scrollend', open);
    setTimeout(open, 900);
    window.scrollTo({ top: top, behavior: 'smooth' });
};

(function () {
    var MAGICS = ['$el', '$refs', '$watch', '$nextTick', '$dispatch', '$wire', '$root', '$store', '$id', '$data'];
    var magicsFor = function (el) {
        return new Proxy({}, {
            has: function (target, key) { return MAGICS.indexOf(key) !== -1; },
            get: function (target, key) { return MAGICS.indexOf(key) !== -1 ? window.Alpine.evaluate(el, key) : undefined; },
        });
    };
    var blocks = {};
    // el: phần tử mang x-data/x-init (truyền $el). args: giá trị từ Blade (thay cho {{ }} / @js()).
    window.xBlock = function (name, el, args) {
        return blocks[name].call(el, magicsFor(el), window.Alpine.$data(el), args || {});
    };

    // livewire/book.blade.php (x-data)
    blocks.bookRoot = function (_m, _s, a) { with (_m) { with (_s) {
return {
    selectedSlots: [],
    selectedRoomId: null,
    selectedRoomIsActive: null,
    fullBookingDiscount: 0,
    hasFullDayBooking: false,
    fullDayDates: [], // Thêm để track ngày nào full booking

    resetSelection() {
        document.querySelectorAll('.selectable.active').forEach(el => el.classList.remove('active'));
        this.selectedSlots = [];
        this.selectedRoomId = null;
        this.selectedRoomIsActive = null;
        this.fullBookingDiscount = 0;
        this.hasFullDayBooking = false;
        this.fullDayDates = [];
    },

    toggleSlot(el, slot) {
        // Ô giờ (book/_slot-cell.blade.php) chỉ gọi toggleSlot($el) — giá/khuyến mãi/giờ nằm trong
        // 1 bảng JSON dùng chung (data-slot-map, render 1 lần ở book-panel bên dưới) thay vì nhúng
        // lặp lại trong @click của từng ô. Tra theo roomId|timeslotId|date lấy từ data-* của ô.
        // Vẫn nhận tham số slot để tương thích nếu nơi khác truyền vào object đầy đủ.
        if (!slot) {
            const cellData = el.dataset;
            const mapEl = el.closest('[data-room-ids]')?.querySelector('[data-slot-map]');
            if (!mapEl) { return; }
            const rawMap = mapEl.textContent;
            if (mapEl._rawMap !== rawMap) {
                mapEl._rawMap = rawMap;
                mapEl._slotMap = JSON.parse(rawMap);
            }
            // Bảng nén dạng { k: [tên trường...], s: { khoá ô: [giá trị...] } } — tên trường chỉ khai
            // báo 1 lần thay vì lặp lại ở từng ô (xem chỗ in data-slot-map bên dưới).
            const entry = mapEl._slotMap.s[cellData.roomId + '|' + cellData.timeslotId + '|' + cellData.date];
            if (!entry) { return; }
            slot = { date: cellData.date, timeslotId: cellData.timeslotId, roomId: cellData.roomId };
            mapEl._slotMap.k.forEach((name, i) => { slot[name] = entry[i]; });
        }

        // is_activated/totalSlotsInRoom/fullBookingDiscountValue/bulkDiscountRules không còn
        // nhúng thẳng trong @click của từng ô (xem book/_slot-cell.blade.php) — đọc lại từ
        // data-room-meta trên wrapper của phòng (book/_desktop-grid.blade.php,
        // book/_mobile.blade.php), gộp vào slot trước khi dùng, giữ nguyên shape/giá trị y hệt
        // trước đây để mọi logic tính giá/discount bên dưới không cần đổi gì.
        const metaEl = el.closest('[data-room-meta]');
        if (metaEl) {
            slot = { ...slot, ...JSON.parse(metaEl.dataset.roomMeta) };
        }

        if (this.selectedRoomId === null) {
            this.selectedRoomId = slot.roomId;
            this.selectedRoomIsActive = slot.is_activated;
        }

        if (this.selectedRoomId !== slot.roomId) {
            return;
        }

        // Use selectedSlots as source of truth (not el.classList) to avoid DOM lag on quick clicks
        const isSelected = this.selectedSlots.some(
            s => s.timeslotId === slot.timeslotId && s.date === slot.date
        );

        // Mobile (book/_mobile.blade.php) và desktop (book/_desktop-grid.blade.php) render 2 DOM
        // riêng biệt cho CÙNG 1 ô khung giờ (cùng phòng/ngày/khung giờ) — bấm chọn ở bên nào thì
        // chỉ el bên đó nhận class active, còn ô song sinh bên kia (ẩn qua CSS lg:hidden/hidden
        // lg:block, không phải bị gỡ khỏi DOM) không được đồng bộ, nên khi đổi kích thước trình
        // duyệt qua breakpoint sẽ thấy ô đã chọn không tô đen dù selectedSlots vẫn đúng. Tìm mọi
        // ô cùng data-room-id/timeslot-id/date (xem _slot-cell.blade.php) để toggle đồng thời,
        // không chỉ riêng el. Chú ý: toàn khối x-data này nằm trong 1 attribute HTML bọc bởi dấu
        // ngoặc kép, nên tuyệt đối không được gõ ký tự ngoặc kép ở bất kỳ đâu trong toàn bộ khối
        // này (kể cả trong comment) — chỉ dùng dấu nháy đơn cho chuỗi.
        const twins = document.querySelectorAll(
            '.selectable[data-room-id=\'' + slot.roomId + '\'][data-timeslot-id=\'' + slot.timeslotId + '\'][data-date=\'' + slot.date + '\']'
        );

        if (isSelected) {
            twins.forEach(twin => twin.classList.remove('active'));
            // Fix: filter by BOTH date AND timeslotId — same timeslot can exist on multiple dates
            this.selectedSlots = this.selectedSlots.filter(
                s => !(s.timeslotId === slot.timeslotId && s.date === slot.date)
            );

            if (this.selectedSlots.length === 0) {
                this.selectedRoomId = null;
                this.selectedRoomIsActive = null;
                this.fullBookingDiscount = 0;
                this.hasFullDayBooking = false;
                this.fullDayDates = [];
            }
        } else {
            twins.forEach(twin => twin.classList.add('active'));
            this.selectedSlots.push(slot);
        }

        this.checkFullBooking();
    },

    checkFullBooking() {
        if (!this.selectedRoomId || this.selectedSlots.length === 0) {
            this.fullBookingDiscount = 0;
            this.hasFullDayBooking = false;
            this.fullDayDates = [];
            return;
        }

        const slotsByDate = {};
        this.selectedSlots.forEach(slot => {
            if (!slotsByDate[slot.date]) {
                slotsByDate[slot.date] = [];
            }
            slotsByDate[slot.date].push(slot);
        });

        const firstSlot = this.selectedSlots[0];
        const totalSlotsInRoom = firstSlot.totalSlotsInRoom;
        const fullBookingDiscountValue = firstSlot.fullBookingDiscountValue;

        let hasFullDay = false;
        this.fullDayDates = [];

        Object.entries(slotsByDate).forEach(([date, slots]) => {
            if (slots.length === totalSlotsInRoom) {
                hasFullDay = true;
                // Chỉ track fullDayDates nếu có cấu hình full_booking_discount
                if (fullBookingDiscountValue) {
                    this.fullDayDates.push(date);
                }
            }
        });

        // Chỉ kích hoạt full booking mode khi có cấu hình giảm giá
        this.hasFullDayBooking = hasFullDay && !!fullBookingDiscountValue;

        if (this.hasFullDayBooking) {
            this.fullBookingDiscount = this.calculateDiscountValue(
                this.totalPrice,
                fullBookingDiscountValue
            );
        } else {
            this.fullBookingDiscount = 0;
        }
    },

    calculateDiscountValue(totalPrice, discountStr) {
        if (!discountStr) return 0;

        if (discountStr.includes('%')) {
            const percentage = parseFloat(discountStr.replace('%', ''));
            return totalPrice * (percentage / 100);
        } else {
            return parseFloat(discountStr.replace(/[.,]/g, ''));
        }
    },

    get totalOriginalPrice() {
        return this.selectedSlots.reduce((sum, s) => sum + (s.basePrice || s.originalPrice || s.price), 0);
    },

    // SỬA: Không tính promo discount cho ngày full booking
    get totalPromoDiscount() {
        return this.selectedSlots.reduce((sum, s) => {
            // Nếu ngày này full booking thì không tính promo discount
            if (this.fullDayDates.includes(s.date)) {
                return sum;
            }
            return sum + (s.promoDiscount || 0);
        }, 0);
    },

    get totalIncreaseAmount() {
        return this.selectedSlots.reduce((sum, s) => {
            return sum + (s.increaseAmount || 0);
        }, 0);
    },

    get totalPrice() {
        return this.selectedSlots.reduce((sum, s) => {
            // Nếu là ngày full booking, tính lại giá = basePrice + increaseAmount
            if (this.fullDayDates.includes(s.date)) {
                return sum + (s.basePrice + s.increaseAmount);
            }
            return sum + s.price;
        }, 0);
    },

    get discountRate() {
        if (this.hasFullDayBooking || this.selectedSlots.length === 0) {
            return 0;
        }

        const rules = this.selectedSlots[0].bulkDiscountRules;
        if (!rules || rules.length === 0) {
            return 0;
        }

        const count = this.selectedSlots.length;
        // Lấy rule có slots <= count, ưu tiên rule cao nhất
        const matched = rules
            .filter(r => count >= r.slots)
            .sort((a, b) => b.slots - a.slots)[0];

        return matched ? matched.discount / 100 : 0;
    },

    get discount() {
        return this.totalPrice * this.discountRate;
    },

    get totalAfterDiscount() {
        return this.totalPrice - this.discount;
    },

    get totalAfterAllDiscounts() {
        return this.totalAfterDiscount - this.fullBookingDiscount;
    },

    // SỬA: Không hiển thị discount promotions cho ngày full booking
    get promotionSummary() {
        const summary = {
            increases: {},
            discounts: {}
        };

        this.selectedSlots.forEach(slot => {
            // Xử lý TĂNG GIÁ (luôn hiển thị)
            if (slot.increasePromotions && slot.increasePromotions.length > 0) {
                slot.increasePromotions.forEach(promo => {
                    if (!summary.increases[promo.name]) {
                        summary.increases[promo.name] = {
                            type: promo.type,
                            total: 0,
                            count: 0
                        };
                    }
                    summary.increases[promo.name].total += promo.amount || 0;
                    summary.increases[promo.name].count++;
                });
            }

            // Xử lý GIẢM GIÁ (chỉ hiển thị nếu KHÔNG phải ngày full booking)
            if (!this.fullDayDates.includes(slot.date)) {
                if (slot.discountPromotions && slot.discountPromotions.length > 0) {
                    slot.discountPromotions.forEach(promo => {
                        if (!summary.discounts[promo.name]) {
                            summary.discounts[promo.name] = {
                                type: promo.type,
                                total: 0,
                                count: 0
                            };
                        }
                        summary.discounts[promo.name].total += promo.amount || 0;
                        summary.discounts[promo.name].count++;
                    });
                }
            }
        });

        return summary;
    }
};
    } } };

    // livewire/location-modal.blade.php (x-data)
    blocks.locationModal = function (_m, _s, a) { with (_m) { with (_s) {
return {
        open: false,
        prompt: false,
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
                this.prompt = false;
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
            this.prompt = false;
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
                this.autoOpen();
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
                    } else {
                        this.autoOpen();
                    }
                })
                .catch(() => this.autoOpen());
        },

        // Lần đầu vào site (chưa chọn khu vực, chưa từng tắt) KHÔNG còn tự bật modal phủ đen toàn
        // màn hình — Google tính đó là "intrusive interstitial" (trừ điểm trang đến từ kết quả tìm
        // kiếm), Googlebot lại không có localStorage nên LẦN NÀO cũng gặp. Thay vào đó tự lấy khu vực:
        //   1. Chỉ có 1 tỉnh/thành đang hoạt động → chọn luôn, khỏi hỏi.
        //   2. Khách đã cho phép định vị từ trước (permissions = granted) → định vị ngầm.
        //   3. Còn lại → thanh gợi ý nhỏ ở cạnh dưới (không che nội dung), bấm mới mở modal.
        // Bot/crawler bỏ qua hết. Khách vẫn mở modal thủ công qua nút 'Chọn khu vực' ở header.
        autoOpen() {
            if (/bot|crawl|spider|slurp|lighthouse|headless/i.test(navigator.userAgent) || navigator.webdriver) return;
            if (localStorage.getItem('home_province_id') || localStorage.getItem('home_location_popup_dismissed')) return;

            if (this.loaded && this.provinces.length === 1) {
                this.selectProvince(this.provinces[0]);
                return;
            }

            const showPrompt = () => { this.prompt = true; };
            if (!navigator.geolocation || !navigator.permissions || !navigator.permissions.query) {
                showPrompt();
                return;
            }
            navigator.permissions.query({ name: 'geolocation' })
                .then((status) => {
                    if (status.state === 'granted') this.detectLocation(showPrompt);
                    else showPrompt();
                })
                .catch(showPrompt);
        },

        openFromPrompt() {
            this.prompt = false;
            this.open = true;
            this.loadProvinces();
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
            this.prompt = false;
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

        // onFail: định vị ngầm từ autoOpen() — lỗi thì gọi onFail (hiện thanh gợi ý) thay vì báo lỗi.
        detectLocation(onFail) {
            const fail = (msg) => { if (onFail) onFail(); else this.error = msg; };
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
                            else fail('Không xác định được vị trí gần bạn.');
                        })
                        .catch(() => fail('Không tải được vị trí.'))
                        .finally(() => { this.detecting = false; });
                },
                () => {
                    fail('Bạn chưa cho phép truy cập vị trí.');
                    this.detecting = false;
                }
            );
        },
    };
    } } };

    // livewire/auth-modal.blade.php (x-data)
    blocks.authModal = function (_m, _s, a) { with (_m) { with (_s) {
return {
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
    };
    } } };

    // components/header/actions/auth-button.blade.php (x-data)
    blocks.authButton = function (_m, _s, a) { with (_m) { with (_s) {
return {
        user: null,
        token: null,

        init() {
            this.loadFromStorage();
            window.addEventListener('auth-state-changed', () => this.loadFromStorage());
            // 'storage' bắt thay đổi localStorage từ tab/cửa sổ khác (đăng nhập/đăng xuất ở tab
            // kia). 'pageshow' + persisted bắt trường hợp trang được phục hồi từ bfcache (nút
            // back/forward) — Alpine không tự chạy lại init() khi đó nên tên cũ bị 'dính cache'.
            window.addEventListener('storage', (e) => {
                if (e.key === 'auth_token' || e.key === 'auth_user') this.loadFromStorage();
            });
            window.addEventListener('pageshow', (e) => {
                if (e.persisted) this.loadFromStorage();
            });
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
    };
    } } };

    // livewire/account-page.blade.php (x-data)
    blocks.accountPage = function (_m, _s, a) { with (_m) { with (_s) {
return {
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
        cccdFile: null,
        cccdPreview: null,
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
            this.cccdFile = null;
            this.cccdPreview = null;
            this.pwError = '';
            this.pwSuccess = '';
            this.currentPw = '';
            this.newPw = '';
            this.newPwConfirm = '';
            this.showPw = false;
            this.editOpen = true;
        },

        onCccdPick(event) {
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
            reader.onload = (e) => { this.cccdPreview = e.target.result; this.cccdFile = file; };
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
            if (!this.cccdFile) {
                this.cccdError = 'Vui lòng chọn ảnh CCCD (mặt có mã QR).';
                return;
            }
            this.cccdLoading = true;
            this.cccdError   = '';
            this.cccdSuccess = '';
            const token = localStorage.getItem('auth_token');
            if (!token) { this.cccdLoading = false; return; }

            try {
                // Upload file tạm qua Livewire rồi để server quét mã QR + lưu hồ sơ (AccountPage::saveCccd).
                await new Promise((resolve, reject) =>
                    this.$wire.upload('cccdQrImage', this.cccdFile, resolve, () => reject(new Error('upload')))
                );
                const result = await this.$wire.saveCccd(token);
                if (!result || !result.ok) { this.cccdError = (result && result.message) || 'Lỗi tải CCCD.'; return; }

                this.cccdSuccess = result.message;
                this.cccdFile = null;
                this.cccdPreview = null;
                window.dispatchEvent(new CustomEvent('auth-state-changed'));
                setTimeout(() => { this.editOpen = false; }, 1200);
            } catch {
                this.cccdError = 'Tải ảnh thất bại. Vui lòng thử lại.';
            } finally {
                this.cccdLoading = false;
            }
        },
    };
    } } };

    // components/header/header-main.blade.php (x-init)
    blocks.headerInit = function (_m, _s, a) { with (_m) { with (_s) {
        let stickyOnThreshold = a.threshold;
        // Ngưỡng bật sticky-pill — mặc định 80px (trang thường)/400px (trang chủ, fallback trước
        // khi đo xong). Trang chủ cần đo ĐỘNG theo vị trí thật của khung tìm kiếm nổi đè banner
        // (#hero-banner-search-overlay, xem flash-sale.blade.php): thanh tìm kiếm RIÊNG của header
        // (hàng 2 + pill gọn) phải ẩn hẳn lúc đầu trang, chỉ hiện sau khi cuộn qua khỏi khung nổi
        // đó — tránh 2 khung tìm kiếm cùng hiện chồng nhau. QUAN TRỌNG: dòng let trên phải là dòng
        // ĐẦU TIÊN (không có comment/khoảng trắng nào đứng trước nó trong x-init) — Alpine chỉ tự
        // bọc IIFE cho x-init nhiều câu lệnh khi phần đầu (sau trim) khớp đúng regex bắt đầu bằng
        // let hoặc const (xem generateFunctionFromString trong livewire.js); nếu có comment đứng
        // trước từ khoá let thì điều kiện này không khớp, Alpine coi cả khối là 1 biểu thức đơn và
        // gán thẳng vào with(scope) — vỡ cú pháp ngay (lỗi Unexpected identifier đã gặp, mất cả
        // buổi mới dò ra được). Đồng thời KHÔNG được để ký tự dấu ngoặc kép ở bất kỳ đâu trong toàn
        // bộ x-init này (kể cả trong comment) — x-init nằm trong 1 thuộc tính HTML bọc bởi dấu
        // ngoặc kép, gặp phải ký tự đó sẽ cắt đứt thuộc tính ngay tại đó (lỗi Unexpected end of
        // input vừa gặp lại, y hệt lỗi đã ghi chú ở toggleSlot() trong book.blade.php trước đây).
        // Đo qua IntersectionObserver thay vì đọc offsetHeight/getBoundingClientRect: callback nhận
        // boundingClientRect đã tính sẵn ở bước layout của frame, không ép reflow đồng bộ. Cách cũ
        // (rAF + setTimeout 0 sau 250ms) vẫn rơi đúng lúc Livewire/Alpine còn đang sửa DOM —
        // Lighthouse đo được ~87ms forced reflow tại đây. Khung bị ẩn (mobile: hidden lg:block)
        // thì rect cao 0 => giữ ngưỡng mặc định như trước.
        const recomputeStickyThreshold = () => {
            const overlay = document.getElementById('hero-banner-search-overlay');
            if (!overlay) return;
            const io = new IntersectionObserver((entries) => {
                io.disconnect();
                const rect = entries[0].boundingClientRect;
                if (rect.height === 0) return;
                stickyOnThreshold = Math.round(rect.bottom + window.scrollY);
            });
            io.observe(overlay);
        };
        setTimeout(recomputeStickyThreshold, 250);
        let __hstResizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(__hstResizeTimer);
            __hstResizeTimer = setTimeout(recomputeStickyThreshold, 200);
        }, { passive: true });
        let ticking = false;
        window.addEventListener('scroll', () => {
            // Một số trang (vd tìm kiếm) cần header giữ nguyên kích thước cố định vì có phần tử
            // sticky khác (bản đồ) neo theo chiều cao header — nếu header co giãn khi cuộn, phần
            // tử đó bị giật theo. window.__headerAlwaysExpanded = true tắt hẳn hành vi thu gọn này.
            if (ticking || window.__headerAlwaysExpanded) return;
            ticking = true;
            requestAnimationFrame(() => {
                // Hysteresis: ngưỡng bật khác ngưỡng tắt (cách nhau 40px) để tránh isSticky
                // nhấp nháy liên tục (gây rung/giật) khi scrollY dao động quanh 1 mốc duy nhất.
                if (!isSticky && window.scrollY > stickyOnThreshold) isSticky = true;
                else if (isSticky && window.scrollY < stickyOnThreshold - 40) isSticky = false;
                ticking = false;
            });
        }, { passive: true });
        // Khi cuộn lại lên đầu (isSticky tắt) thì thanh tìm kiếm đầy đủ đã hiện sẵn rồi,
        // reset searchExpanded để lần cuộn xuống kế tiếp lại bắt đầu từ trạng thái thu gọn.
        // emitFormPinState() bắn CustomEvent sang scope Alpine khác của chính form tìm kiếm (nằm
        // trong hàng 2 header — _banner-form.blade.php, khác cây Alpine, không đọc thẳng
        // isSticky/searchExpanded được) để form biết lúc nào cần ẩn bớt hàng tab RIÊNG của nó (đã
        // có hàng tab hiện ngay trong hàng 1 header rồi — xem khối tab tuyệt đối phía dưới): true
        // bất cứ khi nào KHÔNG phải trạng thái pill gọn (isSticky && !searchExpanded) — tức hễ hàng
        // tab ngoài (hàng 1) đang hiện thì hàng tab riêng trong form phải ẩn đi, ở mọi lúc kể cả
        // chưa cuộn.
        const emitFormPinState = () => {
            const pinned = !isSticky || searchExpanded;
            window.dispatchEvent(new CustomEvent('header-form-pin', { detail: { pinned } }));
        };
        // Gọi ngay từ đầu (không chỉ đợi $watch, vốn chỉ bắn khi có THAY ĐỔI): hàng tab ngoài phải
        // hiện + hàng tab riêng trong form phải ẩn NGAY LÚC TẢI TRANG, không cần đợi người dùng
        // cuộn/mở rộng tìm kiếm trước. Trì hoãn qua setTimeout(...,0) — không gọi thẳng ngay tại
        // đây — vì #main-header-bar (phần tử NGOÀI) chạy x-init này TRƯỚC KHI Alpine kịp khởi tạo
        // xong các component con nằm sâu bên trong hàng 2 (data-bar của _banner-form.blade.php, nơi
        // lắng nghe event này); bắn ngay lúc đó thì listener chưa kịp gắn, mất event. setTimeout 0
        // đẩy lệnh gọi này ra sau toàn bộ lượt duyệt cây Alpine đồng bộ ban đầu của cả trang, đảm
        // bảo mọi component con đã sẵn sàng lắng nghe.
        setTimeout(emitFormPinState, 0);
        $watch('isSticky', val => { if (!val) searchExpanded = false; emitFormPinState(); });
        $watch('searchExpanded', () => emitFormPinState());
        // activeTabMirror chỉ để tô sáng đúng tab (Theo giờ/Qua đêm/Theo ngày) ở hàng tab hiện
        // ngay trong header khi đã cuộn xuống — dữ liệu thật (activeTab) nằm ở scope Alpine khác
        // (_banner-form.blade.php, khác cây), nghe qua CustomEvent 'hero-active-tab-changed' do
        // banner-form tự bắn mỗi khi activeTab đổi.
        window.addEventListener('hero-active-tab-changed', (e) => { activeTabMirror = e.detail.tab; });
    
    } } };

    // livewire/hero-section/_banner-form.blade.php (x-data)
    blocks.heroBannerBar = function (_m, _s, a) { with (_m) { with (_s) {
return {
                        locOpen: false, guestsOpen: false, mobileStep: 1, locating: false, hoverField: null,
                        pinnedToHeader: false,
                        locationSearch: '', selectedLocationSlug: a.loc, mobileLocations: a.locations,
                        selectedGuestsVal: a.guests, selectedRoomType: a.roomType,
                        guestOptions: { '': 'Tất cả', '1': '1 người', '2': '2 người', '3': '3 người', '4': '4 người', '5': '5+ người' },
                        get locationLabel() {
                            if (!this.selectedLocationSlug) return 'Chọn địa điểm';
                            const loc = this.mobileLocations.find(l => l.slug === this.selectedLocationSlug);
                            return loc ? loc.name : 'Chọn địa điểm';
                        },
                        get guestsLabel() { return this.guestOptions[this.selectedGuestsVal] || 'Thêm khách'; },
                        init() {
                            this.syncLocationFromUrl();
                            this.autoFillLocation();
                            this.syncGuestsFromUrl();
                            window.addEventListener('province-selected', () => this.autoFillLocation());
                        },
                        // Trang /s/{slug}?adults=... không truyền $selectedGuests vào component này
                        // — đọc lại từ URL để ô Khách khớp đúng lựa chọn trước đó.
                        syncGuestsFromUrl() {
                            if (this.selectedGuestsVal) return;
                            const adults = window.parseGuestsFromUrl ? window.parseGuestsFromUrl() : '';
                            if (adults && this.guestOptions[adults] !== undefined) this.selectedGuestsVal = adults;
                        },
                        // Trang /s/{slug} không truyền $selectedLocation vào component này — tự đọc
                        // từ URL trước tiên (ưu tiên cao nhất, khớp đúng nơi đang xem) để bấm tìm
                        // kiếm lại không bị rơi mất địa điểm, biến thành tìm toàn quốc ngoài ý muốn.
                        syncLocationFromUrl() {
                            if (this.selectedLocationSlug) return;
                            const slug = window.parseLocationSlugFromUrl ? window.parseLocationSlugFromUrl() : '';
                            if (!slug) return;
                            const loc = this.mobileLocations.find(l => l.slug === slug);
                            if (loc) this.selectedLocationSlug = loc.slug;
                        },
                        // Khách đã đăng nhập và có province_id trên tài khoản (được location-modal
                        // ghi vào localStorage lúc vào trang — xem location-modal.blade.php) thì tự
                        // hiển thị khu vực đó ở ô Địa điểm, không bắt chọn lại. Không đụng tới nếu ô
                        // đã có giá trị sẵn (từ URL hoặc khách đã tự chọn) hoặc khách chưa đăng nhập.
                        autoFillLocation() {
                            if (this.selectedLocationSlug) return;
                            if (!localStorage.getItem('auth_token')) return;
                            const provinceId = localStorage.getItem('home_province_id');
                            if (!provinceId) return;
                            const loc = this.mobileLocations.find(l => String(l.id) === String(provinceId));
                            if (loc) this.selectedLocationSlug = loc.slug;
                        },
                        // this.open = true đặt trong setTimeout (không phải ngay lập tức): click chọn
                        // địa điểm xảy ra ngoài phạm vi field Thời gian, nên @click.outside đóng field
                        // đó cũng bắn ra trong cùng lượt sự kiện này — nếu set open=true ngay thì sẽ
                        // bị handler đó ghi đè lại thành false ngay sau (cùng tick đồng bộ). Đợi 1 tick
                        // (setTimeout 0) để chắc chắn chạy sau handler đó.
                        pickLocation(slug) {
                            this.selectedLocationSlug = slug;
                            this.locOpen = false;
                            const loc = this.mobileLocations.find(l => l.slug === slug);
                            if (loc) window.persistProvinceSelection(loc);
                            setTimeout(() => {
                                this.open = true;
                                this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop));
                            });
                        },
                        locateMe() {
                            this.locating = true;
                            window.heroLocateNearest(
                                (slug) => {},
                                a.locations,
                                (loc) => {
                                    this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug;
                                    window.persistProvinceSelection(loc);
                                    this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep);
                                    setTimeout(() => {
                                        this.open = true;
                                        this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop));
                                    });
                                },
                                (msg) => { this.locating = false; alert(msg); }
                            );
                        },
                        // Pill gọn trong header (đã bị teleport ra ngoài scope này) bắn event này để
                        // yêu cầu mở đúng popup của field tương ứng, thay vì phải bấm 2 lần. Đợi 180ms
                        // (khớp với thời gian giãn hàng-2 ở header-main, 150ms) trước khi mở, vì 2 lý do:
                        // 1) click vào pill cũng là 1 click ngoài phạm vi data-bar (@click.outside bên
                        //    dưới) — set cờ ngay trong cùng lượt xử lý click đó sẽ bị @click.outside
                        //    tắt lại ngay sau; 2) vị trí popup (đặc biệt field date, cần đo bằng JS)
                        //    chỉ chính xác sau khi hàng-2 đã giãn xong, nếu đo lúc đang giãn dở sẽ ra
                        //    toạ độ sai và không tự sửa lại được.
                        handleHeroOpenField(field) {
                            setTimeout(() => {
                                this.locOpen = false; this.guestsOpen = false; this.open = false;
                                if (field === 'loc') { this.locOpen = true; }
                                else if (field === 'guests') { this.guestsOpen = true; }
                                else if (field === 'date') {
                                    this.open = true;
                                    this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownDesktop));
                                }
                            }, 180);
                        }
                    };
    } } };

    // livewire/hero-section/_compact-form.blade.php (x-data)
    blocks.heroCompactBar = function (_m, _s, a) { with (_m) { with (_s) {
return {
                            locOpen: false, guestsOpen: false, mobileStep: 1, locating: false,
                            locationSearch: '', selectedLocationSlug: a.loc, mobileLocations: a.locations,
                            selectedGuestsVal: a.guests,
                            guestOptions: { '': 'Tất cả', '1': '1 người', '2': '2 người', '3': '3 người', '4': '4 người', '5': '5+ người' },
                            get locationLabel() {
                                if (!this.selectedLocationSlug) return 'Chọn địa điểm';
                                const loc = this.mobileLocations.find(l => l.slug === this.selectedLocationSlug);
                                return loc ? loc.name : 'Chọn địa điểm';
                            },
                            get guestsLabel() { return this.guestOptions[this.selectedGuestsVal] || 'Thêm khách'; },
                            init() {
                                this.syncLocationFromUrl();
                                this.autoFillLocation();
                                this.syncGuestsFromUrl();
                                window.addEventListener('province-selected', () => this.autoFillLocation());
                            },
                            // Xem giải thích chi tiết ở _banner-form.blade.php.
                            syncGuestsFromUrl() {
                                if (this.selectedGuestsVal) return;
                                const adults = window.parseGuestsFromUrl ? window.parseGuestsFromUrl() : '';
                                if (adults && this.guestOptions[adults] !== undefined) this.selectedGuestsVal = adults;
                            },
                            // Xem giải thích chi tiết ở _banner-form.blade.php.
                            syncLocationFromUrl() {
                                if (this.selectedLocationSlug) return;
                                const slug = window.parseLocationSlugFromUrl ? window.parseLocationSlugFromUrl() : '';
                                if (!slug) return;
                                const loc = this.mobileLocations.find(l => l.slug === slug);
                                if (loc) this.selectedLocationSlug = loc.slug;
                            },
                            // Khách đã đăng nhập và có province_id trên tài khoản: xem giải thích chi
                            // tiết ở _banner-form.blade.php.
                            autoFillLocation() {
                                if (this.selectedLocationSlug) return;
                                if (!localStorage.getItem('auth_token')) return;
                                const provinceId = localStorage.getItem('home_province_id');
                                if (!provinceId) return;
                                const loc = this.mobileLocations.find(l => String(l.id) === String(provinceId));
                                if (loc) this.selectedLocationSlug = loc.slug;
                            },
                            // this.open = true đặt trong setTimeout: xem giải thích chi tiết ở
                            // _banner-form.blade.php (tránh bị @click.outside của field Thời gian
                            // ghi đè lại thành false trong cùng lượt sự kiện).
                            pickLocation(slug) {
                                this.selectedLocationSlug = slug;
                                this.locOpen = false;
                                const loc = this.mobileLocations.find(l => l.slug === slug);
                                if (loc) window.persistProvinceSelection(loc);
                                setTimeout(() => {
                                    this.open = true;
                                    this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownCompact));
                                });
                            },
                            locateMe() {
                                this.locating = true;
                                window.heroLocateNearest(
                                    (slug) => {},
                                    a.locations,
                                    (loc) => {
                                        this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug;
                                        window.persistProvinceSelection(loc);
                                        this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep);
                                        setTimeout(() => {
                                            this.open = true;
                                            this.$nextTick(() => this.openDateDropdown(this.$refs.dateDropdownCompact));
                                        });
                                    },
                                    (msg) => { this.locating = false; alert(msg); }
                                );
                            }
                        };
    } } };

    // livewire/hero-section.blade.php (x-data)
    blocks.heroMobileModal = function (_m, _s, a) { with (_m) { with (_s) {
return {
                mobileSearchOpen: false,
                locOpen: false, guestsOpen: false, mobileStep: 1, locating: false,
                locationSearch: '', selectedLocationSlug: a.loc, mobileLocations: a.locations,
                selectedGuestsVal: a.guests,
                guestOptions: { '': 'Tất cả', '1': '1 người', '2': '2 người', '3': '3 người', '4': '4 người', '5': '5+ người' },
                get locationLabel() {
                    if (!this.selectedLocationSlug) return 'Chọn địa điểm';
                    const loc = this.mobileLocations.find(l => l.slug === this.selectedLocationSlug);
                    return loc ? loc.name : 'Chọn địa điểm';
                },
                get guestsLabel() { return this.guestOptions[this.selectedGuestsVal] || 'Thêm khách'; },
                init() {
                    this.syncLocationFromUrl();
                    this.autoFillLocation();
                    this.syncGuestsFromUrl();
                    window.addEventListener('province-selected', () => this.autoFillLocation());
                },
                // Xem giải thích chi tiết ở _banner-form.blade.php.
                syncGuestsFromUrl() {
                    if (this.selectedGuestsVal) return;
                    const adults = window.parseGuestsFromUrl ? window.parseGuestsFromUrl() : '';
                    if (adults && this.guestOptions[adults] !== undefined) this.selectedGuestsVal = adults;
                },
                // Xem giải thích chi tiết ở _banner-form.blade.php.
                syncLocationFromUrl() {
                    if (this.selectedLocationSlug) return;
                    const slug = window.parseLocationSlugFromUrl ? window.parseLocationSlugFromUrl() : '';
                    if (!slug) return;
                    const loc = this.mobileLocations.find(l => l.slug === slug);
                    if (loc) this.selectedLocationSlug = loc.slug;
                },
                // Khách đã đăng nhập và có province_id trên tài khoản: xem giải thích chi tiết ở
                // _banner-form.blade.php.
                autoFillLocation() {
                    if (this.selectedLocationSlug) return;
                    if (!localStorage.getItem('auth_token')) return;
                    const provinceId = localStorage.getItem('home_province_id');
                    if (!provinceId) return;
                    const loc = this.mobileLocations.find(l => String(l.id) === String(provinceId));
                    if (loc) this.selectedLocationSlug = loc.slug;
                },
                locateMe() {
                    this.locating = true;
                    window.heroLocateNearest(
                        (slug) => {},
                        a.locations,
                        (loc) => { this.locating = false; this.locOpen = false; this.selectedLocationSlug = loc.slug; this.mobileStep = (this.mobileStep === 1 ? 2 : this.mobileStep); },
                        (msg) => { this.locating = false; alert(msg); }
                    );
                }
            };
    } } };
})();
