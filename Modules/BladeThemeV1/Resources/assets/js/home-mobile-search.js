// Ô tìm kiếm trang chủ MOBILE kiểu Agoda (components/home/mobile-search.blade.php):
//   form (điểm đến / nhận - trả phòng / phòng - khách) → "Bạn muốn đi đâu?" mở màn chọn tỉnh/thành
//   CŨ (GET /api/v1/search/destinations, nguồn provinces.open-api.vn v1) → TÌM mở danh sách card
//   phòng ngay trên trang chủ (GET /api/v1/search/home-rooms — khuyến mãi lên đầu), không bản đồ,
//   không lịch/khung giờ.
// Mỗi lớp toàn màn hình (điểm đến, lịch, khách, kết quả) đẩy 1 mục history nên nút Back của điện
// thoại đóng đúng lớp trên cùng thay vì rời trang chủ.

const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
const RECENT_KEY = 'hms_recent';
const LAST_KEY = 'hms_last';
const MONTHS_AHEAD = 6;

function pad(n) {
    return String(n).padStart(2, '0');
}

function isoOf(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function parseIso(s) {
    const [y, m, d] = String(s).split('-').map(Number);
    return new Date(y, m - 1, d);
}

function todayIso() {
    return isoOf(new Date());
}

function addDays(iso, n) {
    const d = parseIso(iso);
    d.setDate(d.getDate() + n);
    return isoOf(d);
}

// Bỏ dấu + đ → d để gõ "da lat" vẫn ra "Đà Lạt".
function fold(s) {
    return String(s || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim();
}

function readJson(key, fallback) {
    try {
        const v = JSON.parse(localStorage.getItem(key) || 'null');
        return v === null ? fallback : v;
    } catch (e) {
        return fallback;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        /* private mode — bỏ qua */
    }
}

window.homeMobileSearch = function (config) {
    config = config || {};

    return {
        // ── Form ──
        dest: null, // {kind: province|city|branch|nearby, code?, slug?, lat?, lng?, name, subtitle}
        checkin: todayIso(),
        checkout: addDays(todayIso(), 1),
        rooms: 1,
        adults: 2,
        children: 0,
        roomTypes: config.roomTypes || [],

        // ── Lớp toàn màn hình (stack theo history) ──
        stack: [],

        // ── Điểm đến ──
        destinations: [],
        destLoading: false,
        destError: false,
        query: '',
        branchHits: [],
        branchLoading: false,
        recent: [],
        locating: false,
        locateError: '',
        _branchTimer: null,
        _branchSeq: 0,

        // ── Lịch ──
        draftIn: null,
        draftOut: null,
        months: [],

        // ── Khách ──
        draftRooms: 1,
        draftAdults: 2,
        draftChildren: 0,

        // ── Kết quả ──
        results: [],
        meta: null,
        page: 0,
        loadingResults: false,
        resultsError: false,
        sort: 'promo',
        type: '',
        sortOpen: false,
        searched: null, // snapshot tham số đã tìm (để hiện tóm tắt + phân trang)
        _resultsSeq: 0,
        _observer: null,

        get sortOptions() {
            const base = [
                { value: 'promo', label: 'Ưu đãi nhất' },
                { value: 'price_asc', label: 'Giá thấp nhất' },
                { value: 'price_desc', label: 'Giá cao nhất' },
                { value: 'rating', label: 'Đánh giá cao' },
            ];
            return this.searched && this.searched.dest.kind === 'nearby' ? [{ value: 'distance', label: 'Gần nhất' }].concat(base) : base;
        },

        init() {
            this.recent = readJson(RECENT_KEY, []);
            const last = readJson(LAST_KEY, null);
            if (last) {
                if (last.dest) this.dest = last.dest;
                if (last.checkin && last.checkin >= todayIso() && last.checkout > last.checkin) {
                    this.checkin = last.checkin;
                    this.checkout = last.checkout;
                }
                this.rooms = Math.max(1, Number(last.rooms) || 1);
                this.adults = Math.max(1, Number(last.adults) || 2);
                this.children = Math.max(0, Number(last.children) || 0);
            }
            this.months = this.buildMonths();

            window.addEventListener('popstate', (e) => {
                const hms = e.state && Array.isArray(e.state.hms) ? e.state.hms : [];
                this.stack = hms;
                this.lockScroll();
            });

            // Chỉ mobile: lỡ xoay ngang/thu phóng lên desktop thì đóng hết lớp, trả lại cuộn trang.
            window.matchMedia('(min-width: 1024px)').addEventListener('change', (mq) => {
                if (mq.matches && this.stack.length) {
                    this.stack = [];
                    this.lockScroll();
                }
            });
        },

        // ───────────── Lớp + history ─────────────
        isOpen(name) {
            return this.stack.includes(name);
        },
        top() {
            return this.stack[this.stack.length - 1] || null;
        },
        push(name) {
            if (this.top() === name) return;
            this.stack = this.stack.concat([name]);
            try {
                history.pushState(Object.assign({}, history.state || {}, { hms: this.stack.slice() }), '');
            } catch (e) {
                /* noop */
            }
            this.lockScroll();
        },
        pop() {
            if (!this.stack.length) return;
            // popstate cập nhật stack; nếu history không có mục của mình (hiếm), tự đóng.
            const before = this.stack.length;
            history.back();
            setTimeout(() => {
                if (this.stack.length === before) {
                    this.stack = this.stack.slice(0, -1);
                    this.lockScroll();
                }
            }, 350);
        },
        lockScroll() {
            const lock = this.stack.length > 0;
            document.documentElement.style.overflow = lock ? 'hidden' : '';
            document.body.style.overflow = lock ? 'hidden' : '';
            // Ẩn nút liên hệ nổi (.custom-floating-contact, z 9999) đang đè lên nút "Xong"/card.
            document.body.classList.toggle('hms-open', lock);
            // Đang xem kết quả (lớp trên cùng): hiện lại bottom bar của site đè lên lớp kết quả.
            document.body.classList.toggle('hms-results', this.top() === 'results');
        },

        // ───────────── Nhãn hiển thị ─────────────
        dayLabel(iso) {
            if (!iso) return '';
            const d = parseIso(iso);
            return WEEKDAYS[d.getDay()] + ', ' + d.getDate() + ' tháng ' + (d.getMonth() + 1);
        },
        shortDay(iso) {
            if (!iso) return '';
            const d = parseIso(iso);
            return d.getDate() + ' thg ' + (d.getMonth() + 1);
        },
        get nights() {
            return Math.max(1, Math.round((parseIso(this.checkout) - parseIso(this.checkin)) / 86400000));
        },
        get guestsLabel() {
            return this.rooms + ' Phòng, ' + this.adults + ' Người lớn, ' + this.children + ' Trẻ em';
        },
        get guestTotal() {
            return this.adults + this.children;
        },
        vnd(n) {
            return Number(n || 0).toLocaleString('vi-VN') + 'đ';
        },

        // ───────────── Điểm đến ─────────────
        openDest() {
            this.query = '';
            this.branchHits = [];
            this.locateError = '';
            this.push('dest');
            this.loadDestinations();
            this.$nextTick(() => {
                const input = document.getElementById('hms-dest-input');
                if (input) setTimeout(() => input.focus(), 60);
            });
        },
        loadDestinations() {
            if (this.destinations.length || this.destLoading) return;
            this.destLoading = true;
            this.destError = false;
            fetch('/api/v1/search/destinations', { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject(r)))
                .then((json) => {
                    this.destinations = (json.data || []).map((d) => Object.assign({}, d, { _fold: fold(d.short_name) }));
                })
                .catch(() => {
                    this.destError = true;
                })
                .finally(() => {
                    this.destLoading = false;
                });
        },
        destSubtitle(d) {
            if (d.kind === 'city') return 'Thành phố: ' + d.short_name + ', ' + d.province_name + ', Việt Nam';
            return (d.name.indexOf('Thành phố') === 0 ? 'Thành phố: ' : 'Tỉnh: ') + d.short_name + ', Việt Nam';
        },
        destTag(d) {
            return d.kind === 'city' || d.name.indexOf('Thành phố') === 0 ? 'Thành phố' : 'Tỉnh';
        },
        // Không gõ gì: điểm đến đang có phòng (nhiều phòng trước), rồi toàn bộ tỉnh/thành cũ.
        get popularDestinations() {
            return this.destinations
                .filter((d) => d.room_count > 0)
                .sort((a, b) => b.room_count - a.room_count);
        },
        get allProvinces() {
            return this.destinations.filter((d) => d.kind === 'province');
        },
        get filteredDestinations() {
            const q = fold(this.query);
            if (!q) return [];
            return this.destinations
                .filter((d) => d._fold.includes(q) || fold(d.province_name).includes(q))
                .sort((a, b) => {
                    const sa = a._fold.startsWith(q) ? 0 : a._fold.includes(q) ? 1 : 2;
                    const sb = b._fold.startsWith(q) ? 0 : b._fold.includes(q) ? 1 : 2;
                    return sa - sb || (b.room_count > 0) - (a.room_count > 0) || b.room_count - a.room_count;
                })
                .slice(0, 30);
        },
        onQueryInput() {
            clearTimeout(this._branchTimer);
            const q = this.query.trim();
            if (q.length < 2) {
                this.branchHits = [];
                this.branchLoading = false;
                return;
            }
            this.branchLoading = true;
            this._branchTimer = setTimeout(() => {
                const seq = ++this._branchSeq;
                fetch('/api/v1/search/locations?limit=8&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                    .then((r) => (r.ok ? r.json() : Promise.reject(r)))
                    .then((json) => {
                        if (seq !== this._branchSeq) return;
                        this.branchHits = (json.data || []).filter((row) => row.type === 'branch');
                    })
                    .catch(() => {
                        if (seq === this._branchSeq) this.branchHits = [];
                    })
                    .finally(() => {
                        if (seq === this._branchSeq) this.branchLoading = false;
                    });
            }, 250);
        },
        pickDestination(d) {
            this.dest = {
                kind: d.kind,
                code: d.code,
                name: d.short_name,
                subtitle: this.destSubtitle(d),
            };
            this.pop();
        },
        pickBranch(row) {
            this.dest = {
                kind: 'branch',
                slug: row.slug,
                name: row.name,
                subtitle: row.province_name ? 'Cơ sở lưu trú · ' + row.province_name : 'Cơ sở lưu trú',
            };
            this.pop();
        },
        pickRecent(item) {
            this.dest = item.dest;
            if (item.checkin && item.checkin >= todayIso() && item.checkout > item.checkin) {
                this.checkin = item.checkin;
                this.checkout = item.checkout;
            }
            this.rooms = item.rooms || 1;
            this.adults = item.adults || 2;
            this.children = item.children || 0;
            this.pop();
        },
        useNearby() {
            if (!('geolocation' in navigator)) {
                this.locateError = 'Thiết bị không hỗ trợ định vị.';
                return;
            }
            this.locating = true;
            this.locateError = '';
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.locating = false;
                    this.dest = {
                        kind: 'nearby',
                        lat: Number(pos.coords.latitude.toFixed(6)),
                        lng: Number(pos.coords.longitude.toFixed(6)),
                        name: 'Gần vị trí của bạn',
                        subtitle: 'Trong bán kính 10 km',
                    };
                    this.pop();
                },
                () => {
                    this.locating = false;
                    this.locateError = 'Không lấy được vị trí. Hãy bật quyền định vị cho trình duyệt.';
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
            );
        },

        // ───────────── Lịch nhận / trả phòng ─────────────
        buildMonths() {
            const now = new Date();
            const out = [];
            for (let i = 0; i < MONTHS_AHEAD; i++) {
                const first = new Date(now.getFullYear(), now.getMonth() + i, 1);
                const year = first.getFullYear();
                const month = first.getMonth();
                const lead = (first.getDay() + 6) % 7; // tuần bắt đầu thứ 2
                const days = new Date(year, month + 1, 0).getDate();
                const cells = [];
                for (let b = 0; b < lead; b++) cells.push(null);
                for (let d = 1; d <= days; d++) cells.push(year + '-' + pad(month + 1) + '-' + pad(d));
                out.push({ key: year + '-' + month, title: 'Tháng ' + (month + 1) + ', ' + year, cells });
            }
            return out;
        },
        openDates() {
            this.draftIn = this.checkin;
            this.draftOut = this.checkout;
            this.push('dates');
        },
        pickDay(iso) {
            if (!iso || iso < todayIso()) return;
            if (!this.draftIn || this.draftOut || iso <= this.draftIn) {
                this.draftIn = iso;
                this.draftOut = null;
                return;
            }
            this.draftOut = iso;
        },
        dayClass(iso) {
            if (!iso) return 'is-blank';
            const cls = [];
            if (iso < todayIso()) cls.push('is-past');
            if (iso === todayIso()) cls.push('is-today');
            if (iso === this.draftIn) cls.push('is-start');
            if (iso === this.draftOut) cls.push('is-end');
            if (this.draftIn && this.draftOut && iso > this.draftIn && iso < this.draftOut) cls.push('is-range');
            if (this.draftIn && this.draftOut) cls.push('has-range');
            return cls.join(' ');
        },
        dayNum(iso) {
            return iso ? Number(iso.slice(8)) : '';
        },
        get draftNights() {
            if (!this.draftIn || !this.draftOut) return 0;
            return Math.round((parseIso(this.draftOut) - parseIso(this.draftIn)) / 86400000);
        },
        applyDates() {
            if (!this.draftIn) return;
            this.checkin = this.draftIn;
            this.checkout = this.draftOut || addDays(this.draftIn, 1);
            this.pop();
        },

        // ───────────── Phòng & khách ─────────────
        openGuests() {
            this.draftRooms = this.rooms;
            this.draftAdults = this.adults;
            this.draftChildren = this.children;
            this.push('guests');
        },
        step(field, delta) {
            const limits = { draftRooms: [1, 10], draftAdults: [1, 30], draftChildren: [0, 10] };
            const [min, max] = limits[field];
            this[field] = Math.min(max, Math.max(min, this[field] + delta));
            // Mỗi phòng ít nhất 1 người lớn.
            if (this.draftAdults < this.draftRooms) {
                if (field === 'draftRooms') this.draftAdults = this.draftRooms;
                else this.draftRooms = this.draftAdults;
            }
        },
        applyGuests() {
            this.rooms = this.draftRooms;
            this.adults = this.draftAdults;
            this.children = this.draftChildren;
            this.pop();
        },

        // ───────────── Tìm + kết quả ─────────────
        search() {
            if (!this.dest) {
                this.openDest();
                return;
            }
            if (this.checkin < todayIso()) {
                this.checkin = todayIso();
                if (this.checkout <= this.checkin) this.checkout = addDays(this.checkin, 1);
            }

            const snapshot = {
                dest: this.dest,
                checkin: this.checkin,
                checkout: this.checkout,
                rooms: this.rooms,
                adults: this.adults,
                children: this.children,
            };
            writeJson(LAST_KEY, snapshot);
            this.remember(snapshot);

            this.searched = snapshot;
            this.type = '';
            this.sortOpen = false;
            this.sort = this.dest.kind === 'nearby' ? 'distance' : 'promo';
            this.push('results');
            this.fetchResults(true);
            // Gợi ý "điểm đến có phòng" khi không có kết quả.
            this.loadDestinations();
        },
        remember(snapshot) {
            const id = (s) => JSON.stringify([s.dest.kind, s.dest.code || s.dest.slug || s.dest.name]);
            if (snapshot.dest.kind === 'nearby') return;
            this.recent = [snapshot].concat(this.recent.filter((r) => r && r.dest && id(r) !== id(snapshot))).slice(0, 3);
            writeJson(RECENT_KEY, this.recent);
        },
        queryString(page) {
            const s = this.searched;
            const p = new URLSearchParams();
            if (s.dest.kind === 'province' || s.dest.kind === 'city') {
                p.set('dest_kind', s.dest.kind);
                p.set('dest_code', s.dest.code);
            } else if (s.dest.kind === 'branch') {
                p.set('province', s.dest.slug);
            } else if (s.dest.kind === 'nearby') {
                p.set('lat', s.dest.lat);
                p.set('lng', s.dest.lng);
                p.set('radius', 10);
            }
            p.set('checkin', s.checkin);
            p.set('checkout', s.checkout);
            // Lọc theo sức chứa của 1 phòng: chia đều số khách cho số phòng đặt.
            p.set('guests', Math.max(1, Math.ceil((s.adults + s.children) / s.rooms)));
            if (this.type) p.set('type', this.type);
            p.set('sort', this.sort);
            p.set('page', page);
            p.set('per_page', 20);
            return p.toString();
        },
        fetchResults(reset) {
            if (!this.searched) return;
            if (reset) {
                this.results = [];
                this.meta = null;
                this.page = 0;
                this.resultsError = false;
                const list = document.getElementById('hms-results-scroll');
                if (list) list.scrollTop = 0;
            }
            if (this.loadingResults && !reset) return;
            if (this.meta && this.page >= this.meta.last_page) return;

            const seq = ++this._resultsSeq;
            const nextPage = this.page + 1;
            const headers = { Accept: 'application/json' };
            const token = localStorage.getItem('auth_token');
            if (token) headers.Authorization = 'Bearer ' + token;

            this.loadingResults = true;
            fetch('/api/v1/search/home-rooms?' + this.queryString(nextPage), { headers })
                .then((r) => (r.ok ? r.json() : Promise.reject(r)))
                .then((json) => {
                    if (seq !== this._resultsSeq) return;
                    this.results = this.results.concat(json.data || []);
                    this.meta = json.meta || null;
                    this.page = nextPage;
                    this.$nextTick(() => this.observeSentinel());
                })
                .catch(() => {
                    if (seq === this._resultsSeq) this.resultsError = true;
                })
                .finally(() => {
                    if (seq === this._resultsSeq) this.loadingResults = false;
                });
        },
        observeSentinel() {
            const el = document.getElementById('hms-results-sentinel');
            if (!el || !('IntersectionObserver' in window)) return;
            if (!this._observer) {
                this._observer = new IntersectionObserver(
                    (entries) => {
                        if (entries.some((e) => e.isIntersecting) && this.isOpen('results') && !this.loadingResults) {
                            this.fetchResults(false);
                        }
                    },
                    { root: document.getElementById('hms-results-scroll') || null, rootMargin: '400px 0px' }
                );
            }
            this._observer.disconnect();
            this._observer.observe(el);
        },
        setSort(value) {
            this.sortOpen = false;
            if (this.sort === value) return;
            this.sort = value;
            this.fetchResults(true);
        },
        setType(slug) {
            if (this.type === slug) return;
            this.type = slug;
            this.fetchResults(true);
        },
        get sortLabel() {
            const opt = this.sortOptions.find((o) => o.value === this.sort);
            return opt ? opt.label : 'Ưu đãi nhất';
        },
        get hasMore() {
            return !!this.meta && this.page < this.meta.last_page;
        },
        get summaryTitle() {
            if (!this.searched) return '';
            return this.searched.dest.name;
        },
        get summaryLine() {
            if (!this.searched) return '';
            const s = this.searched;
            return this.shortDay(s.checkin) + ' - ' + this.shortDay(s.checkout) + ' · ' + s.rooms + ' phòng · ' + (s.adults + s.children) + ' khách';
        },
        // Chèn tiêu đề "Đang giảm giá" / "Các phòng khác" ở ranh giới 2 nhóm khi xếp theo ưu đãi.
        // Dùng ?. — lúc đổi sắp xếp/lọc, results bị làm rỗng trong khi x-for cũ còn đánh giá chỉ số cũ.
        showPromoHeader(index) {
            return this.sort === 'promo' && index === 0 && !!this.results[0]?.has_promotion;
        },
        showOthersHeader(index) {
            const cur = this.results[index];
            const prev = this.results[index - 1];
            return this.sort === 'promo' && !!cur && !!prev && !cur.has_promotion && !!prev.has_promotion;
        },
        typeName(room) {
            const t = this.roomTypes.find((x) => x.slug === room.type_slug);
            return t ? t.name : '';
        },
        styleLabel(room) {
            return { theo_gio: 'Theo giờ', theo_ngay: 'Theo ngày', qua_dem: 'Qua đêm', theo_thang: 'Theo tháng' }[room.room_style] || '';
        },
        roomImage(room) {
            return (room.thumbnail && (room.thumbnail.card || room.thumbnail.wide)) || room.thumbnail_url || '';
        },
        roomHref(room) {
            const typeUrl = window.__typeUrlSlug ? window.__typeUrlSlug(room.type_slug) : null;
            let href =
                typeUrl && room.branch && room.branch.slug && room.branch.province_slug
                    ? '/' + typeUrl + '/' + room.branch.province_slug + '/' + room.branch.slug + '/' + room.slug + '/'
                    : '/room/' + room.slug + '/';
            // Phòng theo ngày: trang chi tiết tự chọn sẵn khoảng ngày (daterange-picker đọc ?checkin=&checkout=).
            if (room.room_style === 'theo_ngay' && this.searched) {
                href += '?checkin=' + this.searched.checkin + '&checkout=' + this.searched.checkout;
            }
            return href;
        },
        heart(room) {
            return room.wishlist_status ? window.__heartSolidSvg || '♥' : window.__heartOutlineSvg || '♡';
        },
        toggleWishlist(room, el) {
            if (window.__homeToggleWishlist) window.__homeToggleWishlist(room.id, el);
        },
        searchPopular(d) {
            this.dest = { kind: d.kind, code: d.code, name: d.short_name, subtitle: this.destSubtitle(d) };
            this.searched = Object.assign({}, this.searched, { dest: this.dest });
            writeJson(LAST_KEY, this.searched);
            this.remember(this.searched);
            this.type = '';
            this.sort = 'promo';
            this.fetchResults(true);
        },
    };
};
