if (typeof window.roomCalendar === 'undefined') {
    window.roomCalendar = function(bookedRanges, productUrl, basePrice, discount, dayPrices, datePrices) {
        return {
            startDate: null, endDate: null, hoveredDate: null,
            viewYear: new Date().getFullYear(), viewMonth: new Date().getMonth(),
            bookedRanges: bookedRanges || [], productUrl: productUrl,
            basePrice: basePrice || 0, discount: discount || 0,
            dayPrices: dayPrices || {}, datePrices: datePrices || {},
            prevMonth() { if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; } else this.viewMonth--; },
            nextMonth() { if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; } else this.viewMonth++; },
            isoDate(y, m, d) { return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); },
            monthName() { return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' }); },
            daysInMonth() { return new Date(this.viewYear, this.viewMonth + 1, 0).getDate(); },
            firstDay() { var d = new Date(this.viewYear, this.viewMonth, 1).getDay(); return d === 0 ? 6 : d - 1; },
            isToday(d) { var t = new Date(); return d === t.getDate() && this.viewMonth === t.getMonth() && this.viewYear === t.getFullYear(); },
            isPast(d) { var cur = new Date(this.viewYear, this.viewMonth, d); var t = new Date(); t.setHours(0,0,0,0); return cur < t; },
            isBooked(d) {
                var iso = this.isoDate(this.viewYear, this.viewMonth, d);
                for (var i = 0; i < this.bookedRanges.length; i++) {
                    if (iso >= this.bookedRanges[i].start && iso <= this.bookedRanges[i].end) return true;
                }
                return false;
            },
            isStart(d) { return !!this.startDate && this.isoDate(this.viewYear, this.viewMonth, d) === this.startDate; },
            isEnd(d) { return !!this.endDate && this.isoDate(this.viewYear, this.viewMonth, d) === this.endDate; },
            inRange(d) {
                if (!this.startDate) return false;
                var cur = this.isoDate(this.viewYear, this.viewMonth, d), end = this.endDate || this.hoveredDate;
                return !!end && cur > this.startDate && cur < end;
            },
            priceForDate(isoStr) {
                if (this.datePrices[isoStr] !== undefined) return this.datePrices[isoStr];
                var dow = new Date(isoStr + 'T00:00:00').getDay();
                if (this.dayPrices[dow] !== undefined && this.dayPrices[dow] > 0) return this.dayPrices[dow];
                return this.discount > 0 ? Math.round(this.basePrice * (1 - this.discount / 100)) : this.basePrice;
            },
            selectDay(d) {
                if (this.isPast(d) || this.isBooked(d)) return;
                var iso = this.isoDate(this.viewYear, this.viewMonth, d);
                if (!this.startDate || (this.startDate && this.endDate)) {
                    this.startDate = iso; this.endDate = null;
                } else {
                    if (iso <= this.startDate) { this.startDate = iso; this.endDate = null; return; }
                    var blocked = false;
                    for (var i = 0; i < this.bookedRanges.length; i++) {
                        var r = this.bookedRanges[i];
                        if (r.start < iso && r.end > this.startDate) { blocked = true; break; }
                    }
                    if (blocked) { this.startDate = iso; this.endDate = null; }
                    else { this.endDate = iso; }
                }
            },
            get nightCount() {
                if (!this.startDate || !this.endDate) return 0;
                return Math.round((new Date(this.endDate) - new Date(this.startDate)) / 86400000);
            },
            get totalPrice() {
                if (!this.startDate || !this.endDate) return 0;
                var total = 0, d = new Date(this.startDate + 'T00:00:00'), end = new Date(this.endDate + 'T00:00:00');
                while (d < end) { total += this.priceForDate(d.toISOString().slice(0, 10)); d.setDate(d.getDate() + 1); }
                return total;
            },
            hasPromo(d) {
                if (this.isPast(d) || this.isBooked(d)) return false;
                return this.datePrices[this.isoDate(this.viewYear, this.viewMonth, d)] !== undefined;
            },
            dayStyle(d) {
                if (this.isStart(d) || this.isEnd(d)) return 'background:#4e6b4c;border-radius:50%;color:#fff;font-weight:700;';
                if (this.inRange(d)) return 'background:#d4ead4;border-radius:0;color:#1f2937;';
                if (this.isToday(d)) return 'box-shadow:inset 0 0 0 2px #4e6b4c;border-radius:50%;color:#4e6b4c;font-weight:700;';
                if (this.isBooked(d) || this.isPast(d)) return 'color:#d1d5db;';
                if (this.hasPromo(d)) return 'color:#ea580c;font-weight:600;';
                return 'color:#374151;';
            }
        };
    };
}
