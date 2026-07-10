    <script>
        // Trang kết quả tìm kiếm /s/{slug} không truyền $selectedLocation vào hero-section (component
        // dùng chung nhiều nơi, không biết route hiện tại) — đọc thẳng slug từ URL để ô Địa điểm khớp
        // đúng nơi đang xem. Không có URL khớp thì trả '' (giữ nguyên hành vi cũ — để trống).
        window.parseLocationSlugFromUrl = function () {
            const m = window.location.pathname.match(/^\/s\/([^\/?#]+)/);
            if (!m) return '';
            // /s/branch-a,branch-b (xem tất cả nhiều chi nhánh cùng lúc) — không phải 1 tỉnh/thành
            // đơn, ô Địa điểm không có lựa chọn nào khớp nên bỏ qua, để trống như cũ.
            const slug = decodeURIComponent(m[1]);
            return slug.includes(',') ? '' : slug;
        };

        // Cùng lý do như trên — đọc lại ?checkin=&checkout=&buoi= (format submitSearch() đã ghi:
        // "dd/mm/yyyy HH:mm") và ?adults= để ô Thời gian/Khách khớp đúng lựa chọn trước đó, không
        // rơi về placeholder trống khi vào trang kết quả.
        window.parseDateFromUrl = function () {
            const params = new URLSearchParams(window.location.search);
            const result = { dayMode: params.get('buoi') === '2', checkIn: null, checkOut: null, checkInHour: null, checkOutHour: null };

            const parseOne = (str) => {
                const m = str && str.match(/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{1,2}):(\d{2})$/);
                if (!m) return null;
                return { date: new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1])), hour: Number(m[4]) };
            };

            const inParsed  = parseOne(params.get('checkin'));
            const outParsed = parseOne(params.get('checkout'));
            if (inParsed)  { result.checkIn  = inParsed.date;  result.checkInHour  = inParsed.hour; }
            if (outParsed) { result.checkOut = outParsed.date; result.checkOutHour = outParsed.hour; }
            return result;
        };

        window.parseGuestsFromUrl = function () {
            return new URLSearchParams(window.location.search).get('adults') || '';
        };

        // Lưu lại tỉnh/thành khách vừa chọn ở thanh tìm kiếm — dùng chung 1 hàm với location-modal
        // (cùng quy ước: có auth_token thì gửi kèm Bearer, không thì gửi device_token của guest) để
        // trang chủ (home-sections.js) và các nơi khác đọc localStorage home_province_id đều nhất quán.
        window.persistProvinceSelection = function (province) {
            if (!province || !province.id) return;

            localStorage.setItem('home_province_id', province.id);
            if (province.name) localStorage.setItem('home_province_name', province.name);
            window.dispatchEvent(new CustomEvent('province-selected', { detail: province }));

            const token = localStorage.getItem('auth_token');
            const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            const body = { province_id: province.id };

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
        };

        // Dò tỉnh/thành gần nhất theo vị trí trình duyệt (Geolocation API + haversine).
        // wireSetLocation: hàm gọi Livewire setLocation(slug); locations: mảng { slug, lat, lng, ... }.
        window.heroLocateNearest = function(wireSetLocation, locations, onDone, onError) {
            if (!navigator.geolocation) {
                onError && onError('Trình duyệt không hỗ trợ định vị.');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const { latitude, longitude } = pos.coords;
                    const toRad = (v) => (v * Math.PI) / 180;
                    const haversine = (lat1, lon1, lat2, lon2) => {
                        const R = 6371;
                        const dLat = toRad(lat2 - lat1);
                        const dLon = toRad(lon2 - lon1);
                        const a = Math.sin(dLat / 2) ** 2
                            + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
                        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                    };

                    let nearest = null, minDist = Infinity;
                    (locations || []).forEach((loc) => {
                        if (!loc.lat || !loc.lng) return;
                        const d = haversine(latitude, longitude, loc.lat, loc.lng);
                        if (d < minDist) { minDist = d; nearest = loc; }
                    });

                    if (nearest) {
                        wireSetLocation(nearest.slug);
                        onDone && onDone(nearest);
                    } else {
                        onError && onError('Không tìm thấy tỉnh/thành phù hợp gần bạn.');
                    }
                },
                () => onError && onError('Không thể lấy vị trí của bạn. Vui lòng cho phép truy cập vị trí.'),
                { enableHighAccuracy: true, timeout: 8000 }
            );
        };

        window.heroDatePicker = function(dayModeInit, roomTypeInit) {
            return {
                open: false,
                // false = tab "Theo giờ" (1 ngày + badge giờ nhận/trả), true = tab "Theo ngày" (khoảng ngày, giờ cố định 14:00/12:00)
                dayMode: dayModeInit === true,
                // Loại hình dịch vụ (homestay/villa/...) — chỉ compact-form có tab chọn, khai báo
                // ở scope ngoài cùng này (thay vì trong data-bar) vì các tab đó nằm NGOÀI data-bar.
                selectedRoomType: roomTypeInit || 'all',
                checkIn: null,
                checkOut: null,
                hoverDate: null,
                checkInHour: 14,
                checkOutHour: 16,
                // Badge giờ tròn: 06:00 → 22:00, mỗi badge cách nhau 1 tiếng
                hourBadges: Array.from({ length: 17 }, (_, i) => i + 6),
                _dateDropdownCleanup: null,
                // Giữ popup lịch (rộng cố định 640px, canh giữa theo field) luôn nằm trong màn hình.
                // Không làm thế thì ở màn hình hẹp, popup tràn ra ngoài viewport → xuất hiện thanh
                // cuộn ngang → toàn trang bị "rung/giật" mỗi khi mở/đóng hoặc đổi kích thước cửa sổ.
                positionDateDropdown(popupEl) {
                    if (!popupEl || !popupEl.parentElement) return;
                    const box = popupEl.parentElement.getBoundingClientRect();
                    const w = popupEl.offsetWidth || 640;
                    const margin = 12;
                    let left = (box.left + box.width / 2) - w / 2;
                    left = Math.min(Math.max(left, margin), window.innerWidth - w - margin);
                    popupEl.style.left = (left - box.left) + 'px';
                    popupEl.style.transform = 'none';
                },
                openDateDropdown(popupEl) {
                    this.$nextTick(() => this.positionDateDropdown(popupEl));
                    if (this._dateDropdownCleanup) { this._dateDropdownCleanup(); this._dateDropdownCleanup = null; }
                    const update = () => this.positionDateDropdown(popupEl);
                    window.addEventListener('resize', update);
                    this._dateDropdownCleanup = () => window.removeEventListener('resize', update);
                },
                viewMonth: null,
                viewYear: null,
                openField: null,
                monthNames: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
                    'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'
                ],
                init() {
                    const now = new Date();
                    this.viewMonth = now.getMonth();
                    this.viewYear = now.getFullYear();
                    this.syncDateFromUrl();
                    this.$watch('checkIn', () => this.ensureStartHourNotPast());
                    this.$watch('checkInHour', () => this.ensureEndAfterStart());
                    this.$watch('open', val => {
                        if (!val && this._dateDropdownCleanup) {
                            this._dateDropdownCleanup();
                            this._dateDropdownCleanup = null;
                        }
                    });
                },
                // Trang /s/{slug}?checkin=...&checkout=...&buoi=... không truyền $checkIn/$checkOut
                // vào component này — đọc lại từ URL để ô Thời gian khớp đúng lựa chọn trước đó
                // thay vì rơi về placeholder "Thêm ngày".
                syncDateFromUrl() {
                    if (this.checkIn) return;
                    const parsed = window.parseDateFromUrl ? window.parseDateFromUrl() : null;
                    if (!parsed || !parsed.checkIn) return;
                    this.dayMode = parsed.dayMode;
                    this.checkIn = parsed.checkIn;
                    this.checkOut = parsed.checkOut || parsed.checkIn;
                    if (parsed.checkInHour !== null) this.checkInHour = parsed.checkInHour;
                    if (parsed.checkOutHour !== null) this.checkOutHour = parsed.checkOutHour;
                    this.viewMonth = this.checkIn.getMonth();
                    this.viewYear = this.checkIn.getFullYear();
                },
                get viewMonthName() { return this.monthNames[this.viewMonth]; },
                get nextViewMonth() { return this.viewMonth === 11 ? 0 : this.viewMonth + 1; },
                get nextViewYear() { return this.viewMonth === 11 ? this.viewYear + 1 : this.viewYear; },
                get nextViewMonthName() { return this.monthNames[this.nextViewMonth]; },
                prevMonth() {
                    if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; }
                    else { this.viewMonth--; }
                },
                nextMonth() {
                    if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; }
                    else { this.viewMonth++; }
                },
                getDaysInMonth(year, month) { return new Date(year, month + 1, 0).getDate(); },
                getFirstDay(year, month) { return new Date(year, month, 1).getDay(); },
                getCalendarDays(year, month) {
                    const days = [];
                    const firstDay = this.getFirstDay(year, month);
                    for (let i = 0; i < firstDay; i++) days.push(null);
                    for (let d = 1; d <= this.getDaysInMonth(year, month); d++) days.push(new Date(year, month, d));
                    return days;
                },
                // Tab "Theo ngày": chọn khoảng ngày (check-in / check-out khác nhau)
                selectDate(date) {
                    if (!date || this.isPast(date)) return;
                    if (!this.checkIn) {
                        this.checkIn = date;
                        this.checkOut = date;
                    } else if (this.checkIn && this.checkOut && !this.isSameDay(this.checkIn, this.checkOut)) {
                        this.checkIn = date;
                        this.checkOut = date;
                    } else {
                        if (this.isSameDay(date, this.checkIn)) {
                            // giữ nguyên
                        } else if (date < this.checkIn) {
                            this.checkOut = this.checkIn;
                            this.checkIn = date;
                            this.advanceAfterDateRange();
                        } else {
                            this.checkOut = date;
                            this.advanceAfterDateRange();
                        }
                    }
                },
                // Vừa chọn xong khoảng ngày (tab Theo ngày) — tự đóng lịch, mở luôn phần Khách,
                // giống hành vi tự chuyển bước của Airbnb (không cần bấm ra ngoài rồi bấm lại).
                advanceAfterDateRange() {
                    if (!this.dayMode) return;
                    this.$nextTick(() => {
                        this.open = false;
                        this.guestsOpen = true;
                    });
                },
                // Tab "Theo giờ": luôn chỉ 1 ngày duy nhất (check-in = check-out)
                selectSingleDate(date) {
                    if (!date || this.isPast(date)) return;
                    this.checkIn = date;
                    this.checkOut = date;
                },
                isPast(date) {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    return date < today;
                },
                isSameDay(a, b) {
                    return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
                },
                isSelected(date) { return date && (this.isSameDay(date, this.checkIn) || this.isSameDay(date, this.checkOut)); },
                isRangeStart(date) { return date && this.isSameDay(date, this.checkIn); },
                isRangeEnd(date) { return date && this.isSameDay(date, this.checkOut); },
                isInRange(date) {
                    if (!date || !this.checkIn) return false;
                    const end = this.checkOut || this.hoverDate;
                    if (!end) return false;
                    return date > this.checkIn && date < end;
                },
                formatDisplay(date) {
                    if (!date) return '';
                    return `${String(date.getDate()).padStart(2,'0')}/${String(date.getMonth()+1).padStart(2,'0')}/${date.getFullYear()}`;
                },
                get isCheckInToday() {
                    return !!(this.checkIn && this.isSameDay(this.checkIn, new Date()));
                },
                // Badge giờ nhận phòng còn hợp lệ (loại bỏ giờ đã qua nếu chọn ngày hôm nay)
                get availableStartHourBadges() {
                    if (!this.isCheckInToday) return this.hourBadges;
                    const nowH = new Date().getHours();
                    return this.hourBadges.filter(h => h >= nowH);
                },
                // Badge giờ trả phòng: phải sau giờ nhận phòng
                get availableEndHourBadges() {
                    return this.hourBadges.filter(h => h > this.checkInHour);
                },
                ensureStartHourNotPast() {
                    if (!this.isCheckInToday) return;
                    if (this.availableStartHourBadges.includes(this.checkInHour)) return;
                    const next = this.availableStartHourBadges[0];
                    if (next !== undefined) this.checkInHour = next;
                },
                ensureEndAfterStart() {
                    if (this.availableEndHourBadges.includes(this.checkOutHour)) return;
                    const next = this.availableEndHourBadges[0];
                    this.checkOutHour = next !== undefined ? next : 22;
                },
                get displayCheckIn() {
                    if (!this.checkIn) return '';
                    if (this.dayMode) return `${this.formatDisplay(this.checkIn)} 14:00`;
                    return `${this.formatDisplay(this.checkIn)} ${String(this.checkInHour).padStart(2,'0')}:00`;
                },
                get displayCheckOut() {
                    const date = this.checkOut || this.checkIn;
                    if (!date) return '';
                    if (this.dayMode) return `${this.formatDisplay(date)} 12:00`;
                    return `${this.formatDisplay(date)} ${String(this.checkOutHour).padStart(2,'0')}:00`;
                },
                confirm() {
                    this.open = false;
                },
                resetDate() {
                    this.checkIn = null;
                    this.checkOut = null;
                    this.hoverDate = null;
                    this.checkInHour = 14;
                    this.checkOutHour = 16;
                },
                // Toàn bộ lựa chọn (địa điểm, ngày giờ, khách, loại hình) đều chỉ là state Alpine
                // phía client — không có round-trip Livewire nào cho tới tận lúc bấm Tìm kiếm, để
                // mọi thao tác chọn cảm thấy tức thì (không phải chờ mạng) giống Airbnb. Lúc bấm
                // Tìm kiếm mới build URL và điều hướng thẳng sang trang kết quả (JS tự fetch qua
                // /api/v1/search ở trang đó), không cần gọi $wire cho bước này nữa.
                submitSearch() {
                    const params = {};
                    if (this.selectedRoomType && this.selectedRoomType !== 'all') params.type = this.selectedRoomType;
                    if (this.selectedGuestsVal) params.adults = this.selectedGuestsVal;
                    if (this.checkIn) {
                        params.checkin  = this.displayCheckIn;
                        params.checkout = this.displayCheckOut;

                        // Tab "Theo giờ": ngoài checkin/checkout (chỉ dùng để loại phòng đã bị đặt
                        // trùng), gửi thêm time_type=slot + date + time_from/time_to để API lọc luôn
                        // theo khung giờ (chỉ phòng có khung giờ NẰM GỌN trong [time_from, time_to]
                        // mới hợp lệ — xem RoomSearchService::search()), không chỉ lọc phòng đã đặt.
                        if (!this.dayMode) {
                            const d = this.checkIn;
                            params.time_type = 'slot';
                            params.date      = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                            params.time_from = String(this.checkInHour).padStart(2, '0') + ':00';
                            params.time_to   = String(this.checkOutHour).padStart(2, '0') + ':00';
                        }
                    }
                    params.buoi = this.dayMode ? '2' : '1';

                    const query = new URLSearchParams(params).toString();
                    const path  = '/s/' + (this.selectedLocationSlug ? encodeURIComponent(this.selectedLocationSlug) : '');
                    const url   = path + (query ? '?' + query : '');

                    if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                        window.Livewire.navigate(url);
                    } else {
                        window.location.href = url;
                    }
                },
                cancel() { this.open = false; },
            };
        };
    </script>
