    <script>
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

        window.heroDatePicker = function() {
            return {
                open: false,
                checkIn: null,
                checkOut: null,
                hoverDate: null,
                checkInHour: 14,
                checkInMin: 0,
                checkOutHour: 12,
                checkOutMin: 0,
                checkInHourOpen: false,
                checkOutHourOpen: false,
                checkInHourPos: { openUp: true, top: 0, bottom: 0, left: 0, width: 0 },
                checkOutHourPos: { openUp: true, top: 0, bottom: 0, left: 0, width: 0 },
                _hourDropdownCleanup: null,
                computeHourDropdownPos(boxEl) {
                    const r = boxEl.getBoundingClientRect();
                    const panelH = 180; // ước lượng chiều cao dropdown (max-height panel)
                    const openUp = r.top - 6 >= panelH || (window.innerHeight - r.bottom - 6) < panelH;
                    return openUp
                        ? { openUp: true, bottom: window.innerHeight - r.top + 6, top: 0, left: r.left, width: Math.max(r.width, 160) }
                        : { openUp: false, top: r.bottom + 6, bottom: 0, left: r.left, width: Math.max(r.width, 160) };
                },
                openHourDropdown(which, boxEl) {
                    if (this._hourDropdownCleanup) { this._hourDropdownCleanup(); this._hourDropdownCleanup = null; }

                    const otherKey = which === 'checkIn' ? 'checkOutHourOpen' : 'checkInHourOpen';
                    const openKey = which === 'checkIn' ? 'checkInHourOpen' : 'checkOutHourOpen';
                    const posKey = which === 'checkIn' ? 'checkInHourPos' : 'checkOutHourPos';

                    this[otherKey] = false;
                    const willOpen = !this[openKey];
                    this[openKey] = willOpen;

                    if (!willOpen) return;

                    const update = () => { this[posKey] = this.computeHourDropdownPos(boxEl); };
                    update();

                    // scroll không nổi bọt (bubble) nên phải bắt ở pha capture để nghe được cả scroll trong khung con
                    window.addEventListener('scroll', update, true);
                    window.addEventListener('resize', update);
                    this._hourDropdownCleanup = () => {
                        window.removeEventListener('scroll', update, true);
                        window.removeEventListener('resize', update);
                    };
                    this.$watch(openKey, val => {
                        if (!val && this._hourDropdownCleanup) {
                            this._hourDropdownCleanup();
                            this._hourDropdownCleanup = null;
                        }
                    });
                },
                viewMonth: null,
                viewYear: null,
                openField: null,
                monthNames: ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
                    'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'
                ],
                hours: Array.from({ length: 24 }, (_, i) => i),
                minutes: [0, 15, 30, 45],
                init() {
                    const now = new Date();
                    this.viewMonth = now.getMonth();
                    this.viewYear = now.getFullYear();
                    const correct = () => this.$nextTick(() => this.ensureCheckOutAfterCheckIn());
                    this.$watch('checkIn', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkInHour', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkInMin', () => { this.ensureCheckInNotPast(); correct(); });
                    this.$watch('checkOut', correct);
                    this.$watch('checkOutHour', correct);
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
                        } else {
                            this.checkOut = date;
                        }
                    }
                    this.ensureCheckOutAfterCheckIn();
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
                get availableCheckoutHours() {
                    if (!this.isSameDayBooking) return this.hours;
                    return this.hours.filter(h => {
                        if (h > this.checkInHour) return true;
                        if (h === this.checkInHour) return this.minutes.some(m => m > this.checkInMin);
                        return false;
                    });
                },
                get availableCheckoutMinutes() {
                    if (!this.isSameDayBooking || this.checkOutHour !== this.checkInHour) return this.minutes;
                    return this.minutes.filter(m => m > this.checkInMin);
                },
                get isCheckInToday() {
                    return !!(this.checkIn && this.isSameDay(this.checkIn, new Date()));
                },
                get minCheckInHour() {
                    return this.isCheckInToday ? new Date().getHours() : 0;
                },
                get availableCheckInHours() {
                    if (!this.isCheckInToday) return this.hours;
                    const now = new Date();
                    return this.hours.filter(h => {
                        if (h > now.getHours()) return true;
                        if (h === now.getHours()) return this.minutes.some(m => m > now.getMinutes());
                        return false;
                    });
                },
                get availableCheckInMinutes() {
                    if (!this.isCheckInToday) return this.minutes;
                    const now = new Date();
                    if (this.checkInHour > now.getHours()) return this.minutes;
                    if (this.checkInHour === now.getHours()) return this.minutes.filter(m => m > now.getMinutes());
                    return [];
                },
                ensureCheckInNotPast() {
                    if (!this.isCheckInToday) return;
                    const now = new Date();
                    const nowH = now.getHours(), nowM = now.getMinutes();
                    if (this.checkInHour > nowH) return;
                    if (this.checkInHour === nowH) {
                        if (this.minutes.some(m => m > nowM && m === this.checkInMin)) return;
                    }
                    const nextH = this.availableCheckInHours[0];
                    if (nextH === undefined) return;
                    this.checkInHour = nextH;
                    const validMins = nextH === nowH ? this.minutes.filter(m => m > nowM) : this.minutes;
                    this.checkInMin = validMins[0] ?? 0;
                },
                get displayCheckIn() {
                    if (!this.checkIn) return '';
                    return `${this.formatDisplay(this.checkIn)} ${String(this.checkInHour).padStart(2,'0')}:${String(this.checkInMin).padStart(2,'0')}`;
                },
                get displayCheckOut() {
                    const date = this.checkOut || this.checkIn;
                    if (!date) return '';
                    return `${this.formatDisplay(date)} ${String(this.checkOutHour).padStart(2,'0')}:${String(this.checkOutMin).padStart(2,'0')}`;
                },
                get isSameDayBooking() {
                    return !!(this.checkIn && this.checkOut && this.isSameDay(this.checkIn, this.checkOut));
                },
                ensureCheckOutAfterCheckIn(forceOutHour) {
                    if (!this.isSameDayBooking) return;
                    const inH = this.checkInHour, inM = this.checkInMin;
                    const outH = (forceOutHour !== undefined) ? Number(forceOutHour) : this.checkOutHour;
                    const outM = this.checkOutMin;
                    let newH = outH, newM = outM;
                    if (outH < inH) {
                        newH = inH;
                        const nxt = this.minutes.find(m => m > inM);
                        if (nxt !== undefined) { newM = nxt; } else { newH = Math.min(inH + 1, 23); newM = 0; }
                    } else if (outH === inH && outM <= inM) {
                        const nxt = this.minutes.find(m => m > inM);
                        if (nxt !== undefined) { newM = nxt; } else { newH = Math.min(inH + 1, 23); newM = 0; }
                    }
                    if (newH !== this.checkOutHour) this.checkOutHour = newH;
                    if (newM !== this.checkOutMin) this.checkOutMin = newM;
                },
                async confirm() {
                    if (this.checkIn) {
                        await this.$wire.set('checkIn', this.displayCheckIn);
                        await this.$wire.set('checkOut', this.displayCheckOut);
                    }
                    this.open = false;
                },
                async submitSearch() {
                    if (this.checkIn) {
                        await this.$wire.set('checkIn', this.displayCheckIn);
                        await this.$wire.set('checkOut', this.displayCheckOut);
                    }
                    await this.$wire.search();
                },
                cancel() { this.open = false; },
            };
        };
    </script>
