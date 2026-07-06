{{-- Date Range Picker Component - 1 tháng + Info panel --}}
@php
    $pricePerNight      = $pricePerNight      ?? 0;
    $productDiscount    = $productDiscount    ?? 0;
    $bookedRanges       = $bookedRanges       ?? [];
    $adminBlockedRanges = $adminBlockedRanges ?? [];
    $datePrices         = $datePrices         ?? [];
    $dayPrices          = $dayPrices          ?? [];
    $promoDiscounts     = $promoDiscounts     ?? [];
    $promoLabels        = $promoLabels        ?? [];
    $surchargeData      = $surchargeData      ?? [];
@endphp
@once
@push('scripts')
<script>
function dateRangePicker(pricePerNight) {
    return {
        startDate: null,
        endDate: null,
        hoveredDate: null,
        viewYear: new Date().getFullYear(),
        viewMonth: new Date().getMonth(),
        pricePerNight: pricePerNight || 0,

        bookedRanges: @json($bookedRanges),
        adminBlockedRanges: @json($adminBlockedRanges),
        datePrices: @json($datePrices),
        dayPrices: @json($dayPrices),
        productDiscount: {{ (int)$productDiscount }},
        promoDiscounts: @json($promoDiscounts),
        promoLabels: @json($promoLabels),
        surchargeData: @json($surchargeData),
        couponDiscountAmount: @entangle('couponDiscountAmount'),
        // Tổng tiền THẬT của cả chuyến (phòng + phụ thu khách + dịch vụ thêm - coupon),
        // do server tính (calculateDateRangeTotal) — luôn đồng bộ dù widget này có wire:ignore.
        serverTotalAmount: @entangle('totalAmount'),
        extraFeeAmount: @entangle('extraFee'),
        guestsCount: @entangle('guests'),
        selectedServicesQty: @entangle('selectedServices'),
        showTotalDetail: false,
        additionalServicesList: @json($additionalServices ? $additionalServices->map(fn($s) => ['id' => $s->id, 'name' => $s->name, 'price' => (int) $s->price])->values() : []),
        get selectedServicesDetail() {
            var self = this, result = [];
            var qtyMap = this.selectedServicesQty || {};
            this.additionalServicesList.forEach(function(svc) {
                var qty = parseInt(qtyMap[svc.id] || 0);
                if (qty > 0) result.push({ id: svc.id, name: svc.name, price: svc.price, qty: qty, lineTotal: svc.price * qty });
            });
            return result;
        },
        get selectedServicesTotal() {
            return this.selectedServicesDetail.reduce(function(sum, s) { return sum + s.lineTotal; }, 0);
        },

        init() {
            var ci = null, co = null;
            // 1. Check sessionStorage (set by card "Đặt ngay")
            try {
                var saved = JSON.parse(sessionStorage.getItem('booking_preselect') || 'null');
                if (saved && saved.checkin && saved.checkout) {
                    ci = saved.checkin; co = saved.checkout;
                    sessionStorage.removeItem('booking_preselect');
                }
            } catch(e) {}
            // 2. Fallback: URL params (then clean them from address bar)
            if (!ci || !co) {
                var params = new URLSearchParams(window.location.search);
                ci = params.get('checkin'); co = params.get('checkout');
                if (ci || co) { history.replaceState({}, '', window.location.pathname); }
            }
            if (ci && /^\d{4}-\d{2}-\d{2}$/.test(ci)) {
                this.startDate = ci;
                var parts = ci.split('-');
                this.viewYear  = parseInt(parts[0]);
                this.viewMonth = parseInt(parts[1]) - 1;
            }
            if (co && /^\d{4}-\d{2}-\d{2}$/.test(co)) {
                this.endDate = co;
                // Push to Livewire after next tick to ensure $wire is ready
                var self = this;
                this.$nextTick(function() {
                    try {
                        self.$wire.set('startTime', self.startDate);
                        self.$wire.set('endTime', self.endDate);
                    } catch(e) {}
                });
            }
        },

        get viewYear2() { return this.viewMonth === 11 ? this.viewYear + 1 : this.viewYear; },
        get viewMonth2() { return this.viewMonth === 11 ? 0 : this.viewMonth + 1; },

        prevMonth() {
            if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; } else this.viewMonth--;
        },
        nextMonth() {
            if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; } else this.viewMonth++;
        },
        isoDate(y, m, d) {
            return y + '-' + String(m+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        },
        monthNameFor(y, m) {
            return new Date(y, m, 1).toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' });
        },
        daysInMonthFor(y, m) { return new Date(y, m + 1, 0).getDate(); },
        firstDayFor(y, m) { var d = new Date(y, m, 1).getDay(); return d === 0 ? 6 : d - 1; },
        isTodayFor(y, m, d) { var t = new Date(); return d === t.getDate() && m === t.getMonth() && y === t.getFullYear(); },
        isPastFor(y, m, d) { var cur = new Date(y, m, d); var t = new Date(); t.setHours(0,0,0,0); return cur < t; },

        // Check if date falls within any booked range (inclusive)
        isBookedFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.bookedRanges.length; i++) {
                if (iso >= this.bookedRanges[i].start && iso <= this.bookedRanges[i].end) return true;
            }
            return false;
        },
        // First day of a booked range
        isBookedStartFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.bookedRanges.length; i++) {
                if (iso === this.bookedRanges[i].start) return true;
            }
            return false;
        },
        // Last day of a booked range
        isBookedEndFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.bookedRanges.length; i++) {
                if (iso === this.bookedRanges[i].end) return true;
            }
            return false;
        },
        // Middle day of a booked range (not start, not end)
        isBookedMiddleFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.bookedRanges.length; i++) {
                if (iso > this.bookedRanges[i].start && iso < this.bookedRanges[i].end) return true;
            }
            return false;
        },

        // Admin-blocked ranges (khóa lịch)
        isAdminBlockedFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.adminBlockedRanges.length; i++) {
                if (iso >= this.adminBlockedRanges[i].start && iso <= this.adminBlockedRanges[i].end) return true;
            }
            return false;
        },
        isAdminBlockedStartFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.adminBlockedRanges.length; i++) {
                if (iso === this.adminBlockedRanges[i].start) return true;
            }
            return false;
        },
        isAdminBlockedEndFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.adminBlockedRanges.length; i++) {
                if (iso === this.adminBlockedRanges[i].end) return true;
            }
            return false;
        },
        isAdminBlockedMiddleFor(y, m, d) {
            var iso = this.isoDate(y, m, d);
            for (var i = 0; i < this.adminBlockedRanges.length; i++) {
                if (iso > this.adminBlockedRanges[i].start && iso < this.adminBlockedRanges[i].end) return true;
            }
            return false;
        },
        // Unavailable = booked OR admin-blocked
        isUnavailableFor(y, m, d) {
            return this.isBookedFor(y, m, d) || this.isAdminBlockedFor(y, m, d);
        },

        isStartFor(y, m, d) { return !!this.startDate && this.isoDate(y, m, d) === this.startDate; },
        isEndFor(y, m, d) { return !!this.endDate && this.isoDate(y, m, d) === this.endDate; },
        inRangeFor(y, m, d) {
            if (!this.startDate) return false;
            var cur = this.isoDate(y, m, d), end = this.endDate || this.hoveredDate;
            return !!end && cur > this.startDate && cur < end && !this.isUnavailableFor(y, m, d);
        },

        // Selection blocked if day is booked/admin-blocked OR if range would cross such ranges
        selectDayFor(y, m, d) {
            if (this.isPastFor(y, m, d) || this.isUnavailableFor(y, m, d)) return;
            var iso = this.isoDate(y, m, d);
            if (!this.startDate || (this.startDate && this.endDate)) {
                this.startDate = iso; this.endDate = null;
            } else {
                if (iso <= this.startDate) { this.startDate = iso; this.endDate = null; return; }
                // Block if any booked/admin-blocked range falls between startDate and chosen end
                var blocked = false;
                for (var i = 0; i < this.bookedRanges.length; i++) {
                    var r = this.bookedRanges[i];
                    if (r.start < iso && r.end > this.startDate) { blocked = true; break; }
                }
                if (!blocked) {
                    for (var j = 0; j < this.adminBlockedRanges.length; j++) {
                        var ar = this.adminBlockedRanges[j];
                        if (ar.start <= iso && ar.end >= this.startDate) { blocked = true; break; }
                    }
                }
                if (blocked) { this.startDate = iso; this.endDate = null; }
                else { this.endDate = iso; }
            }
        },
        formatDisplay(iso) {
            if (!iso) return '--';
            var p = iso.split('-'); return p[2] + '/' + p[1] + '/' + p[0];
        },
        dayLabelForIso(isoStr) {
            var days = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
            return days[new Date(isoStr + 'T00:00:00').getDay()];
        },
        basePriceForDate(isoStr) {
            if (Object.prototype.hasOwnProperty.call(this.datePrices, isoStr) && this.datePrices[isoStr] !== null) {
                return this.datePrices[isoStr];
            }
            var dow = new Date(isoStr + 'T00:00:00').getDay();
            if (this.dayPrices[dow] !== undefined && this.dayPrices[dow] > 0) return this.dayPrices[dow];
            var d = this.productDiscount || 0;
            return d > 0 ? Math.round(this.pricePerNight * (1 - d / 100)) : this.pricePerNight;
        },
        priceSourceForDate(isoStr) {
            if (Object.prototype.hasOwnProperty.call(this.datePrices, isoStr) && this.datePrices[isoStr] !== null) {
                return 'override';
            }
            var dow = new Date(isoStr + 'T00:00:00').getDay();
            if (this.dayPrices[dow] !== undefined && this.dayPrices[dow] > 0) return 'weekday';
            return 'base';
        },
        sourceLabelForDate(isoStr) {
            var source = this.priceSourceForDate(isoStr);
            if (source === 'override') return 'Giá ngày đặc biệt';
            if (source === 'weekday') return 'Giá theo thứ';
            return 'Giá gốc';
        },
        surchargeAmountForDate(isoStr) {
            var list = this.surchargeData[isoStr];
            if (!list || !list.length) return 0;
            var base = this.basePriceForDate(isoStr);
            var total = 0;
            for (var i = 0; i < list.length; i++) {
                var s = list[i];
                if (s.type === 'increase_percentage') total += Math.round(base * s.value / 100);
                else total += Math.round(s.value);
            }
            return total;
        },
        priceForDate(isoStr) {
            var p = this.basePriceForDate(isoStr);
            var disc = this.promoDiscounts[isoStr] || 0;
            var afterDiscount = disc > 0 ? Math.round(p * (1 - disc / 100)) : p;
            return afterDiscount + this.surchargeAmountForDate(isoStr);
        },
        promoLabelFor(y, m, d) {
            return this.promoLabels[this.isoDate(y, m, d)] || '';
        },
        get nightCount() {
            if (!this.startDate || !this.endDate) return 0;
            return Math.round((new Date(this.endDate) - new Date(this.startDate)) / 86400000);
        },
        get totalPrice() {
            if (!this.startDate || !this.endDate) return 0;
            var total = 0, d = new Date(this.startDate + 'T00:00:00'), end = new Date(this.endDate + 'T00:00:00');
            while (d < end) { total += this.priceForDate(this.isoDate(d.getFullYear(), d.getMonth(), d.getDate())); d.setDate(d.getDate() + 1); }
            return total;
        },
        get originalSubtotal() {
            if (!this.startDate || !this.endDate) return 0;
            var total = 0, d = new Date(this.startDate + 'T00:00:00'), end = new Date(this.endDate + 'T00:00:00');
            while (d < end) {
                var iso = this.isoDate(d.getFullYear(), d.getMonth(), d.getDate());
                total += this.basePriceForDate(iso) + this.surchargeAmountForDate(iso);
                d.setDate(d.getDate() + 1);
            }
            return total;
        },
        get promoSavingsTotal() {
            return Math.max(0, this.originalSubtotal - this.totalPrice);
        },
        get couponSavingsTotal() {
            return Math.max(0, Number(this.couponDiscountAmount || 0));
        },
        get finalDisplayTotal() {
            return Math.max(0, this.totalPrice - this.couponSavingsTotal);
        },
        get grandSavingsTotal() {
            return this.promoSavingsTotal + this.couponSavingsTotal;
        },
        get displayLabel() {
            if (!this.startDate) return 'Ch\u1ecdn ng\u00e0y nh\u1eadn & tr\u1ea3 ph\u00f2ng';
            if (!this.endDate) return this.formatDisplay(this.startDate) + ' \u2192 Ch\u1ecdn ng\u00e0y tr\u1ea3';
            return this.formatDisplay(this.startDate) + ' \u2013 ' + this.formatDisplay(this.endDate);
        },
        hasPromo(y, m, d) {
            if (this.isPastFor(y, m, d) || this.isUnavailableFor(y, m, d)) return false;
            var iso = this.isoDate(y, m, d);
            return this.promoDiscounts[iso] !== undefined || !!this.promoLabels[iso];
        },
        dayStyle(y, m, d) {
            if (this.isAdminBlockedFor(y, m, d)) return 'background:#e5e7eb;color:#9ca3af;';
            if (this.isBookedFor(y, m, d)) return 'background:#fee2e2;color:#fca5a5;';
            if (this.isStartFor(y, m, d) || this.isEndFor(y, m, d)) return 'background:var(--color-primary);color:#fff;border-radius:50%;';
            if (this.inRangeFor(y, m, d)) return 'background:rgba(var(--color-primary-rgb),0.12);color:var(--color-primary);';
            if (this.isTodayFor(y, m, d)) return 'border:2px solid var(--color-primary);color:var(--color-primary);border-radius:50%;';
            if (this.hasPromo(y, m, d)) return 'color:#ea580c;font-weight:600;';
            return 'color:#374151;';
        },
        dayClass(y, m, d) {
            return {
                'opacity-30 cursor-not-allowed': this.isPastFor(y, m, d),
                'cursor-not-allowed': this.isUnavailableFor(y, m, d),
                'rounded-l-lg !rounded-r-none': (this.isBookedStartFor(y, m, d) && !this.isBookedEndFor(y, m, d)) || (this.isAdminBlockedStartFor(y, m, d) && !this.isAdminBlockedEndFor(y, m, d)),
                'rounded-r-lg !rounded-l-none': (this.isBookedEndFor(y, m, d) && !this.isBookedStartFor(y, m, d)) || (this.isAdminBlockedEndFor(y, m, d) && !this.isAdminBlockedStartFor(y, m, d)),
                '!rounded-none': this.isBookedMiddleFor(y, m, d) || this.isAdminBlockedMiddleFor(y, m, d) || (this.inRangeFor(y, m, d) && !this.isUnavailableFor(y, m, d)),
                '!rounded-r-none': this.isStartFor(y, m, d) && (this.endDate || this.hoveredDate) && !this.isUnavailableFor(y, m, d),
                '!rounded-l-none': this.isEndFor(y, m, d) && !this.isUnavailableFor(y, m, d),
            };
        },
        get rangePromos() {
            if (!this.startDate || !this.endDate) return [];
            var seen = {}, result = [];
            var d = new Date(this.startDate + 'T00:00:00');
            var end = new Date(this.endDate + 'T00:00:00');
            while (d < end) {
                var iso = this.isoDate(d.getFullYear(), d.getMonth(), d.getDate());
                var lbl = this.promoLabels[iso];
                var disc = this.promoDiscounts[iso];
                if (lbl && !seen[lbl]) { seen[lbl] = true; result.push({ label: lbl, discount: disc }); }
                d.setDate(d.getDate() + 1);
            }
            return result;
        },
        get rangeDayPrices() {
            var days = ['CN','T2','T3','T4','T5','T6','T7'], result = [];
            for (var dow in this.dayPrices) {
                if (this.dayPrices[dow] > 0) result.push({ day: days[parseInt(dow)], price: this.dayPrices[dow] });
            }
            return result;
        },
        get rangeBreakdown() {
            if (!this.startDate || !this.endDate) return [];
            var result = [];
            var d = new Date(this.startDate + 'T00:00:00');
            var end = new Date(this.endDate + 'T00:00:00');

            while (d < end) {
                var iso = this.isoDate(d.getFullYear(), d.getMonth(), d.getDate());
                var basePrice = this.basePriceForDate(iso) + this.surchargeAmountForDate(iso);
                var finalPrice = this.priceForDate(iso);
                var discount = Math.max(0, basePrice - finalPrice);
                result.push({
                    iso: iso,
                    dayLabel: this.dayLabelForIso(iso),
                    displayDate: this.formatDisplay(iso),
                    sourceLabel: this.sourceLabelForDate(iso),
                    sourceType: this.priceSourceForDate(iso),
                    basePrice: basePrice,
                    finalPrice: finalPrice,
                    discount: discount,
                    promoLabel: this.promoLabels[iso] || '',
                    promoDiscount: this.promoDiscounts[iso] || 0,
                });
                d.setDate(d.getDate() + 1);
            }

            return result;
        }
    };
}
</script>
@endpush
@endonce

<div x-data="dateRangePicker({{ (int)$pricePerNight }})"
     x-init="
         $watch('endDate', function(val) {
             if (val && startDate) {
                 try { $wire.set('startTime', startDate); $wire.set('endTime', val); } catch(e){}
             }
         });
         $watch('startDate', function(val) {
             if (!val) { try { $wire.set('startTime', ''); $wire.set('endTime', ''); } catch(e){} }
         });
     "
     class="w-full">

    {{-- Layout 2 cột: Calendar (trái 60%) + Info panel (phải 40%) --}}
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-gray-100">

            {{-- ── CỘT TRÁI: Calendar Apple-style (60%) ── --}}
            <div class="md:w-[60%] flex flex-col bg-white">

                {{-- Header: Tháng Năm (trái) + Mũi tên nav (phải) --}}
                <div class="flex items-center justify-between px-3 pt-3 pb-1">
                    <span class="text-[13px] font-bold text-gray-900 capitalize tracking-tight"
                          x-text="monthNameFor(viewYear, viewMonth)"></span>
                    <div class="flex items-center gap-0.5">
                        <button type="button" @click="prevMonth()"
                                class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 active:bg-gray-200 transition-colors text-gray-500">
                            <svg class="w-[16px] h-[16px]" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <button type="button" @click="nextMonth()"
                                class="w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 active:bg-gray-200 transition-colors text-gray-500">
                            <svg class="w-[16px] h-[16px]" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Day labels --}}
                <div class="grid grid-cols-7 px-2 sm:px-3 mb-0">
                    <template x-for="day in ['T2','T3','T4','T5','T6','T7','CN']">
                        <div class="text-center text-[9px] sm:text-[11px] font-semibold tracking-widest text-gray-400 py-1" x-text="day"></div>
                    </template>
                </div>

                {{-- Days grid --}}
                <div class="grid grid-cols-7 px-2 sm:px-3 pb-2 gap-y-0.5">
                    <template x-for="n in firstDayFor(viewYear, viewMonth)"><div></div></template>
                    <template x-for="d in daysInMonthFor(viewYear, viewMonth)" :key="'cal-'+d">
                        <button
                            type="button"
                            :disabled="isPastFor(viewYear, viewMonth, d) || isUnavailableFor(viewYear, viewMonth, d)"
                            @click="selectDayFor(viewYear, viewMonth, d)"
                            @mouseenter="!isPastFor(viewYear, viewMonth, d) && !isUnavailableFor(viewYear, viewMonth, d) && (hoveredDate = isoDate(viewYear, viewMonth, d))"
                            @mouseleave="hoveredDate = null"
                            class="relative w-full aspect-square max-h-[100px] flex items-center justify-center select-none transition-all duration-150 rounded-lg hover:bg-gray-100"
                            :class="dayClass(viewYear, viewMonth, d)"
                            :style="dayStyle(viewYear, viewMonth, d)"
                            :title="promoLabelFor(viewYear, viewMonth, d)"
                        >
                            {{-- Normal date --}}
                            <span x-show="!isBookedFor(viewYear, viewMonth, d) && !isAdminBlockedFor(viewYear, viewMonth, d)"
                                  class="text-[13px] sm:text-[15px] font-bold leading-none" x-text="d"></span>
                            {{-- Booked: red strikethrough --}}
                            <span x-show="isBookedFor(viewYear, viewMonth, d)"
                                  class="text-[13px] sm:text-[15px] font-bold leading-none"
                                  style="color:#fca5a5;text-decoration:line-through" x-text="d"></span>
                            {{-- Admin-blocked: gray with lock --}}
                            <span x-show="isAdminBlockedFor(viewYear, viewMonth, d)"
                                  class="flex flex-col items-center leading-none">
                                <span class="text-[11px] sm:text-[13px] font-bold" style="color:#9ca3af;" x-text="d"></span>
                                <span style="font-size:8px;line-height:1;">🔒</span>
                            </span>
                        </button>
                    </template>
                </div>

                {{-- Footer trạng thái chọn ngày --}}
                <div class="border-t border-gray-100 px-3 py-2 flex items-center justify-between">
                    <button type="button"
                            @click="startDate = null; endDate = null; hoveredDate = null"
                            class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-red-500 border border-red-300 rounded-full px-3 py-1 hover:bg-red-50 hover:border-red-400 active:bg-red-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        Xóa
                    </button>
                    <span class="text-[12px] text-gray-400 italic" x-show="!startDate">Chọn ngày nhận phòng</span>
                    <span class="text-[12px] text-gray-400 italic" x-show="startDate && !endDate">Chọn ngày trả phòng</span>
                    <span class="text-[12px] font-bold" style="color:var(--color-primary)"
                          x-show="startDate && endDate"
                          x-text="nightCount + ' đêm · ' + formatDisplay(startDate) + ' → ' + formatDisplay(endDate)"></span>
                </div>
            </div>

            {{-- ── CỘT PHẢI: Info panel (40%) ── --}}
            <div class="md:w-[40%] flex flex-col">

                {{-- Header info panel --}}
                <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2" style="background:#f8faf8">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" style="color:var(--color-primary)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                    <h4 class="text-sm font-bold text-gray-700">Ưu đãi & Thông tin giá</h4>
                </div>

                {{-- Placeholder: chưa chọn ngày -- ẩn trên mobile --}}
                <div x-show="!startDate || !endDate"
                     class="hidden md:flex flex-1 flex-col items-center justify-center px-4 py-8 gap-3 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-gray-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <p class="text-xs text-gray-400 italic leading-relaxed">Chọn ngày nhận &amp; trả phòng<br>để xem ưu đãi áp dụng</p>
                </div>

                {{-- Info khi đã chọn đủ ngày --}}
                <div x-show="startDate && endDate"
                     class="flex-1 overflow-y-auto px-3 py-3 space-y-2 md:max-h-[580px]">

                    {{-- Giá phòng tạm tính (chưa gồm dịch vụ thêm / phụ thu khách) --}}
                    <div class="rounded-xl p-3" style="background:rgba(var(--color-primary-rgb),0.08); border:1px solid rgba(var(--color-primary-rgb),0.2)">
                        <p class="text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--color-primary)">Giá phòng tạm tính</p>
                        <p class="text-2xl font-extrabold leading-none" style="color:var(--color-primary)"
                           x-text="finalDisplayTotal.toLocaleString('vi-VN') + 'đ'"></p>
                        <div class="mt-2 space-y-1 text-[11px]">
                            <div class="flex items-center justify-between text-gray-500">
                                <span>Giá trước ưu đãi</span>
                                <span class="font-semibold" x-text="originalSubtotal.toLocaleString('vi-VN') + 'đ'"></span>
                            </div>
                            <div class="flex items-center justify-between text-rose-600" x-show="promoSavingsTotal > 0">
                                <span>Khuyến mãi</span>
                                <span class="font-semibold" x-text="'-' + promoSavingsTotal.toLocaleString('vi-VN') + 'đ'"></span>
                            </div>
                            <div class="flex items-center justify-between text-emerald-600" x-show="couponSavingsTotal > 0">
                                <span>Mã giảm giá</span>
                                <span class="font-semibold" x-text="'-' + couponSavingsTotal.toLocaleString('vi-VN') + 'đ'"></span>
                            </div>
                            <div class="flex items-center justify-between text-gray-400">
                                <span>Số đêm</span>
                                <span class="font-semibold" x-text="nightCount + ' đêm'"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Tổng cộng cho cả chuyến — số THẬT từ server, luôn cập nhật ngay khi đổi dịch vụ/số khách,
                         không cần bấm "Đặt phòng" mới thấy --}}
                    <div class="rounded-xl p-3" style="background:#111827;">
                        <p class="text-[10px] font-bold uppercase tracking-wider mb-1 text-gray-300">Tổng cộng cho cả chuyến</p>
                        <p class="text-2xl font-extrabold leading-none text-white"
                           x-text="Number(serverTotalAmount || 0).toLocaleString('vi-VN') + 'đ'"></p>
                        <div class="flex items-center justify-between mt-1">
                            <p class="text-[11px] text-gray-400">Đã gồm phụ thu khách &amp; dịch vụ thêm (nếu có)</p>
                            <button type="button" @click="showTotalDetail = true"
                                    class="shrink-0 text-[11px] font-semibold underline text-gray-300 hover:text-white transition-colors">
                                Xem chi tiết
                            </button>
                        </div>
                    </div>

                    {{-- Popup chi tiết: dịch vụ đã chọn (kèm số lượng) + phụ thu khách --}}
                    <template x-teleport="body">
                        <div x-show="showTotalDetail" x-cloak
                             class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                             style="background:rgba(0,0,0,0.5)"
                             @click.self="showTotalDetail = false">
                            <div x-show="showTotalDetail"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="bg-white rounded-2xl shadow-xl w-full max-w-sm max-h-[80vh] overflow-y-auto">
                                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                                    <h4 class="text-sm font-bold text-gray-800">Chi tiết tổng tiền</h4>
                                    <button type="button" @click="showTotalDetail = false"
                                            class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <div class="px-4 py-3 space-y-3">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500">Giá phòng (<span x-text="nightCount"></span> đêm)</span>
                                        <span class="font-semibold text-gray-800" x-text="finalDisplayTotal.toLocaleString('vi-VN') + 'đ'"></span>
                                    </div>

                                    <div class="flex items-center justify-between text-sm" x-show="Number(extraFeeAmount || 0) > 0">
                                        <span class="text-gray-500">Phụ thu <span x-text="guestsCount"></span> khách</span>
                                        <span class="font-semibold text-gray-800" x-text="Number(extraFeeAmount || 0).toLocaleString('vi-VN') + 'đ'"></span>
                                    </div>

                                    <template x-if="selectedServicesDetail.length > 0">
                                        <div class="space-y-1.5">
                                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Dịch vụ đã chọn</p>
                                            <template x-for="svc in selectedServicesDetail" :key="svc.id">
                                                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2">
                                                    <span class="text-xs text-gray-700">
                                                        <span x-text="svc.name"></span>
                                                        <span class="text-gray-400">× <span x-text="svc.qty"></span></span>
                                                    </span>
                                                    <span class="text-xs font-semibold text-gray-800" x-text="svc.lineTotal.toLocaleString('vi-VN') + 'đ'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="selectedServicesDetail.length === 0">
                                        <p class="text-xs text-gray-400 italic">Chưa chọn dịch vụ thêm nào</p>
                                    </template>

                                    <div class="border-t border-gray-100 pt-3 flex items-center justify-between">
                                        <span class="text-sm font-bold text-gray-800">Tổng cộng</span>
                                        <span class="text-lg font-extrabold" style="color:var(--color-primary)"
                                              x-text="Number(serverTotalAmount || 0).toLocaleString('vi-VN') + 'đ'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Danh sách khuyến mãi trong khoảng ngày --}}
                    <template x-if="rangePromos.length > 0">
                        <div class="space-y-2">
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Khuyến mãi áp dụng</p>
                            <template x-for="(promo, i) in rangePromos" :key="i">
                                <div class="flex items-start gap-2.5 rounded-xl border border-red-100 bg-red-50 px-3 py-2.5">
                                    <div class="mt-0.5 w-5 h-5 shrink-0 rounded-full bg-red-500 flex items-center justify-center">
                                        <svg class="w-3 h-3 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold text-red-700 leading-tight" x-text="promo.label"></p>
                                        <p class="text-[10px] text-red-400 mt-0.5">Áp dụng cho ngày trong khoảng đã chọn</p>
                                    </div>
                                    <span class="shrink-0 text-[10px] font-bold text-white bg-red-500 rounded-full px-2 py-0.5"
                                          x-text="'-' + promo.discount + '%'"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Không có khuyến mãi --}}
                    <template x-if="rangePromos.length === 0">
                        <div class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2.5 flex items-center gap-2 text-gray-400">
                            <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="8.01"/></svg>
                            <p class="text-xs italic">Không có khuyến mãi cho khoảng ngày này</p>
                        </div>
                    </template>

                    {{-- Mã giảm giá đã áp dụng --}}
                    <template x-if="couponSavingsTotal > 0">
                        <div class="flex items-start gap-2.5 rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2.5">
                            <div class="mt-0.5 w-5 h-5 shrink-0 rounded-full bg-emerald-500 flex items-center justify-center">
                                <svg class="w-3 h-3 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-emerald-700 leading-tight">Mã giảm giá đã được áp dụng</p>
                                <p class="text-[10px] text-emerald-500 mt-0.5">Tiết kiệm thêm <span x-text="couponSavingsTotal.toLocaleString('vi-VN') + 'đ'"></span></p>
                            </div>
                        </div>
                    </template>

                    {{-- Chi tiết từng đêm (accordion - mặc định đóng) --}}
                    <div x-data="{ openNights: false }" class="space-y-1">
                        <button type="button" @click="openNights = !openNights"
                                class="w-full flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 hover:bg-gray-100 transition-colors">
                            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Chi tiết từng đêm</span>
                            <svg class="w-3.5 h-3.5 text-gray-400 transition-transform duration-200"
                                 :class="openNights ? 'rotate-180' : ''"
                                 xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div x-show="openNights" x-collapse class="space-y-2 pt-1">
                            <template x-for="(item, i) in rangeBreakdown" :key="item.iso">
                                <div class="rounded-xl border border-gray-100 bg-white px-3 py-2.5 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <p class="text-xs font-bold text-gray-800" x-text="item.dayLabel + ', ' + item.displayDate"></p>
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                      :class="item.sourceType === 'override' ? 'bg-emerald-50 text-emerald-700' : (item.sourceType === 'weekday' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600')"
                                                      x-text="item.sourceLabel"></span>
                                            </div>
                                            <p class="mt-1 text-[11px] text-gray-500" x-show="item.discount > 0">
                                                <span>Giá gốc: </span>
                                                <span class="line-through" x-text="item.basePrice.toLocaleString('vi-VN') + 'đ'"></span>
                                            </p>
                                            <div class="mt-1" x-show="item.promoLabel">
                                                <p class="text-[11px] font-medium text-rose-600" x-text="item.promoLabel"></p>
                                                <p class="text-[10px] text-rose-400" x-text="'-' + item.promoDiscount + '%'"></p>
                                            </div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <p class="text-sm font-extrabold text-gray-900" x-text="item.finalPrice.toLocaleString('vi-VN') + 'đ'"></p>
                                            <p class="text-[10px] text-rose-500 font-semibold" x-show="item.discount > 0"
                                               x-text="'Tiết kiệm ' + item.discount.toLocaleString('vi-VN') + 'đ'"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Giá theo ngày trong tuần (nếu có) --}}
                    <template x-if="rangeDayPrices.length > 0">
                        <div class="space-y-1.5">
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Giá theo ngày</p>
                            <template x-for="(item, i) in rangeDayPrices" :key="i">
                                <div class="flex items-center justify-between rounded-xl border border-blue-100 bg-blue-50 px-3 py-2">
                                    <span class="text-xs font-semibold text-blue-700" x-text="item.day"></span>
                                    <span class="text-xs font-bold text-blue-600" x-text="item.price.toLocaleString('vi-VN') + 'đ/đêm'"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

        </div>
    </div>

</div>
