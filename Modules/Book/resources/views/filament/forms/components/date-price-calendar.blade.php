@php
    // Danh sách KM / phụ thu / mã được gắn — chỉ loại CÒN tác dụng (xem
    // DailyRoomPricingService::options(): bỏ KM hết hạn/thiếu ngày, bỏ voucher cá nhân — trước đây
    // 3791 voucher cá nhân bị render 3 lần × mỗi phòng → HTML ~50MB → hết RAM, 500). Render bằng
    // Alpine x-for từ JSON nên mỗi phòng chỉ mang 1 bản dữ liệu.
    $options   = app(\App\Services\DailyRoomPricingService::class)->options(auth()->user());
    $statePath = $getStatePath();
    $roomId    = $getRoomId();
@endphp

@once
    @include('book::filament.forms.components.partials._styles')
@endonce

{{-- Chỉ dùng nháy ĐƠN trong JS của x-data (attribute nằm trong nháy kép). --}}
<div
    x-data="{
        slots: $wire.$entangle('{{ $statePath }}'),
        roomId: @js($roomId),
        basePrice: @js($getBasePrice()),
        defaultCheckin:  @js($getDefaultCheckin()),
        defaultCheckout: @js($getDefaultCheckout()),
        promoOptions:     @js($options['promotions']),
        surchargeOptions: @js($options['surcharges']),
        couponOptions:    @js($options['coupons']),
        promoMeta: {},
        weekdayNames: [[1,'T2'],[2,'T3'],[3,'T4'],[4,'T5'],[5,'T6'],[6,'T7'],[7,'CN']],
        viewYear:  new Date().getFullYear(),
        viewMonth: new Date().getMonth(),

        day:  { open:false, date:null, price:'', checkin:'', checkout:'', promos:[], coupons:[], showCoupons:false, couponQ:'', busy:false, qDir:'down', qUnit:'percent', qValue:'', qBusy:false },
        bulk: { open:false, mode:'add', action:'promo', from:'', to:'', weekdays:[], price:'', checkin:'', checkout:'', promos:[], coupons:[], replace:false, removeKind:'all', couponQ:'', busy:false,
                promoMode:'custom', cDir:'down', cUnit:'percent', cValue:'' },

        // ── KM nhập theo giá trị: Giảm/Tăng × %/số tiền → loại Promotion tương ứng ──
        quickType(dir, unit) { return (dir==='up' ? 'increase_' : '') + (unit==='percent' ? 'percentage' : 'fixed'); },
        quickValid(unit, v) { const n = parseInt(v,10); return n > 0 && (unit!=='percent' || n <= 100); },
        quickLabel(dir, unit, v) { const n = parseInt(v,10)||0; return (dir==='up' ? 'Tăng ' : 'Giảm ') + (unit==='percent' ? n+'%' : n.toLocaleString('vi-VN')+'đ'); },
        // KM ảo id -1 để xem trước giá khi đang nhập giá trị (chưa tạo trên server).
        customMeta() { const b = this.bulk; return { id:-1, type: this.quickType(b.cDir, b.cUnit), value: parseInt(b.cValue,10)||0, start_at:'1970-01-01 00:00:00', end_at:'9999-12-31 23:59:59' }; },
        addOption(opt) {
            const list = this.isUp(opt) ? this.surchargeOptions : this.promoOptions;
            const i = list.findIndex(o => o.id === opt.id);
            if (i >= 0) list.splice(i, 1, opt); else list.unshift(opt);
            this.promoMeta[opt.id] = opt;
        },
        async addDayQuick() {
            const d = this.day;
            if (d.qBusy || !this.quickValid(d.qUnit, d.qValue)) return;
            d.qBusy = true;
            const opt = await $wire.call('createQuickPromotion', this.roomId, this.quickType(d.qDir, d.qUnit), parseInt(d.qValue,10), d.date, d.date);
            d.qBusy = false;
            if (!opt) return;
            this.addOption(opt);
            if (!d.promos.includes(opt.id)) d.promos.push(opt.id);
            d.qValue = '';
        },

        init() { [...this.promoOptions, ...this.surchargeOptions].forEach(p => { this.promoMeta[p.id] = p; }); },

        // ── Ngày / định dạng ──
        // Ngày theo giờ MÁY (VN) — toISOString() là UTC nên trước 7h sáng sẽ ra hôm qua.
        localIso(dt) { return dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0')+'-'+String(dt.getDate()).padStart(2,'0'); },
        get todayIso() { return this.localIso(new Date()); },
        isoDate(d) { return this.viewYear+'-'+String(this.viewMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0'); },
        isPast(d) { return this.isoDate(d) < this.todayIso; },
        isoWeekday(iso) { const w = new Date(iso+'T00:00:00').getDay(); return w===0?7:w; },
        get daysInMonth() { return new Date(this.viewYear, this.viewMonth+1, 0).getDate(); },
        get firstDayOffset() { const d=new Date(this.viewYear,this.viewMonth,1).getDay(); return d===0?6:d-1; },
        get monthLabel() { return new Date(this.viewYear,this.viewMonth,1).toLocaleDateString('vi-VN',{month:'long',year:'numeric'}); },
        prevMonth() { if(this.viewMonth===0){this.viewMonth=11;this.viewYear--;}else this.viewMonth--; },
        nextMonth() { if(this.viewMonth===11){this.viewMonth=0;this.viewYear++;}else this.viewMonth++; },
        formatDate(iso, withDay=false) { if(!iso) return ''; return new Date(iso+'T00:00:00').toLocaleDateString('vi-VN', withDay ? {weekday:'short',day:'2-digit',month:'2-digit'} : {day:'2-digit',month:'2-digit',year:'numeric'}); },
        formatPrice(p) { const n=parseInt(String(p??'').replace(/[^0-9]/g,''),10); return isNaN(n)?'':n.toLocaleString('vi-VN')+'đ'; },
        formatShort(n) { n=Number(n)||0; if(n>=1000000) return (Math.round(n/100000)/10).toLocaleString('vi-VN')+'tr'; if(n>=1000) return Math.round(n/1000)+'k'; return String(n); },
        parsePrice(p) { const n=parseInt(String(p??'').replace(/[^0-9]/g,''),10); return isNaN(n)?0:n; },
        hhmm(t, fallback) { return String(t||fallback||'').slice(0,5); },
        toggle(arr, id) { const n=Number(id), i=arr.indexOf(n); if(i>=0) arr.splice(i,1); else arr.push(n); },
        wdLabel(wd) { return wd.length ? this.weekdayNames.filter(w => wd.includes(w[0])).map(w => w[1]).join(', ') : 'mọi ngày'; },

        // ── Giá cuối 1 đêm — CÙNG công thức PromotionCalculator::calculateForDate() của mọi luồng đặt
        //    phòng: giá ghi đè (hoặc giá gốc) → cộng phụ thu → trừ KM (KM % tính trên giá sau phụ thu).
        getSlot(iso) { if(!this.slots||!iso) return null; return this.slots.find(s=>s.date&&String(s.date).slice(0,10)===iso)??null; },
        slotPromoIds(slot) { return [...(slot?.surcharges||[]), ...(slot?.promotions||[])].map(Number); },
        isUp(p) { return !!p && (p.type==='increase_percentage'||p.type==='increase_fixed'); },
        promoApplies(p, iso) { return !!p && !!p.start_at && !!p.end_at && (iso+' 00:00:00') < p.end_at && (iso+' 23:59:59') > p.start_at; },
        promoShort(p) { if(!p) return ''; const v=Number(p.value); return ({percentage:'−'+v+'%', fixed:'−'+this.formatShort(v), increase_percentage:'+'+v+'%', increase_fixed:'+'+this.formatShort(v)})[p.type]||''; },
        calcFinal(base, ids, iso) {
            const promos = (ids||[]).map(id => Number(id) === -1 ? this.customMeta() : this.promoMeta[Number(id)]).filter(p => this.promoApplies(p, iso));
            let afterInc = base;
            promos.forEach(p => { if (p.type==='increase_fixed') afterInc += p.value; else if (p.type==='increase_percentage') afterInc += Math.round(afterInc*p.value/100); });
            let fin = afterInc;
            promos.forEach(p => { if (p.type==='fixed') fin -= Math.min(fin, p.value); else if (p.type==='percentage') fin -= Math.round(afterInc*p.value/100); });
            return Math.max(0, fin);
        },
        nightInfo(iso) {
            const slot = this.getSlot(iso), override = slot?.price != null && slot.price !== '';
            const base = override ? this.parsePrice(slot.price) : this.basePrice;
            return { base, fin: this.calcFinal(base, this.slotPromoIds(slot), iso), override, slot };
        },
        cellClass(d) {
            const iso = this.isoDate(d), slot = this.getSlot(iso);
            if (this.day.open && this.day.date === iso) return 'cal-cell--editing';
            if (this.isPast(d)) return 'cal-cell--past';
            if (slot?.price != null) return 'cal-cell--has-slot';
            if (slot && (this.slotPromoIds(slot).length || (slot.coupons||[]).length)) return 'cal-cell--has-promo';
            return 'cal-cell--normal';
        },
        cellPriceClass(iso) {
            const n = this.nightInfo(iso);
            if (n.fin < n.base) return 'cal-price-tag--discount';
            if (n.fin > n.base) return 'cal-price-tag--surcharge';
            return n.override ? 'cal-price-tag' : 'cal-price-tag--base';
        },

        // ══ POPUP 1 NGÀY ══
        openDay(d) {
            if (this.isPast(d)) return;
            const iso = this.isoDate(d), slot = this.getSlot(iso);
            this.day = { open:true, date:iso,
                price: slot?.price != null ? String(slot.price).replace(/[^0-9]/g,'') : '',
                checkin: this.hhmm(slot?.checkin, this.defaultCheckin), checkout: this.hhmm(slot?.checkout, this.defaultCheckout),
                promos: this.slotPromoIds(slot), coupons: (slot?.coupons||[]).map(Number),
                showCoupons: (slot?.coupons||[]).length > 0, couponQ:'', busy:false, qDir:'down', qUnit:'percent', qValue:'', qBusy:false };
        },
        closeDay() { this.day.open = false; },
        get dayBase()  { return this.day.price!=='' ? parseInt(this.day.price,10) : this.basePrice; },
        get dayFinal() { return this.day.date ? this.calcFinal(this.dayBase, this.day.promos, this.day.date) : 0; },
        get dayApplied() { return this.day.promos.map(id => this.promoMeta[id]).filter(p => p && this.promoApplies(p, this.day.date)); },
        get dayHasSlot() { return !!this.getSlot(this.day.date); },
        get dayEmpty() { return this.day.price==='' && !this.day.promos.length && !this.day.coupons.length && this.day.checkin===this.defaultCheckin && this.day.checkout===this.defaultCheckout; },
        async saveDay() {
            if (this.day.busy) return;
            if (this.dayEmpty) { if (this.dayHasSlot) await this.resetDay(); else this.closeDay(); return; }
            this.day.busy = true;
            await $wire.call('saveSingleDate', this.roomId, this.day.date, this.day.price!=='' ? parseInt(this.day.price,10) : null,
                this.day.checkin || null, this.day.checkout || null, [...this.day.promos], [], [...this.day.coupons]);
            this.day.busy = false; this.closeDay();
        },
        async resetDay() {
            if (this.day.busy) return;
            this.day.busy = true;
            await $wire.call('deleteSingleDate', this.roomId, this.day.date);
            this.day.busy = false; this.closeDay();
        },
        filteredCoupons(q) { q=(q||'').toLowerCase().trim(); const list = q ? this.couponOptions.filter(c => c.label.toLowerCase().includes(q)) : this.couponOptions; return list.slice(0, 60); },

        // ══ POPUP HÀNG LOẠT (thêm + gỡ gộp 1 chỗ) ══
        openBulk(mode='add') {
            this.bulk = { open:true, mode, action: mode==='add' ? 'promo' : 'reset', from:this.todayIso, to:'', weekdays:[], price:'',
                checkin:this.defaultCheckin, checkout:this.defaultCheckout, promos:[], coupons:[], replace:false, removeKind:'all', couponQ:'', busy:false, promoMode:'custom', cDir:'down', cUnit:'percent', cValue:'' };
        },
        closeBulk() { this.bulk.open = false; },
        setMode(mode) { this.bulk.mode = mode; this.bulk.action = mode==='add' ? 'promo' : 'reset'; this.bulk.promos=[]; this.bulk.coupons=[]; this.bulk.replace=false; },
        setRange(kind) {
            const t = new Date(); t.setHours(0,0,0,0);
            const from = new Date(t), to = new Date(t);
            if (kind==='7d') to.setDate(to.getDate()+6);
            if (kind==='30d') to.setDate(to.getDate()+29);
            if (kind==='month') { to.setMonth(to.getMonth()+1, 0); }
            if (kind==='next') { from.setMonth(from.getMonth()+1, 1); to.setMonth(to.getMonth()+2, 0); }
            if (kind==='year') { to.setFullYear(to.getFullYear(), 11, 31); }
            this.bulk.from = this.localIso(from); this.bulk.to = this.localIso(to);
        },
        get bulkDates() {
            const b = this.bulk, out = [];
            if (!b.from || !b.to || b.to < b.from) return out;
            for (let dt = new Date(b.from+'T00:00:00'); this.localIso(dt) <= b.to && out.length < 400; dt.setDate(dt.getDate()+1)) {
                const iso = this.localIso(dt);
                if (!b.weekdays.length || b.weekdays.includes(this.isoWeekday(iso))) out.push(iso);
            }
            return out;
        },
        get bulkTooLong() { const b=this.bulk; return !!b.from && !!b.to && (new Date(b.to)-new Date(b.from))/86400000 + 1 > 400; },
        // Giá 1 ngày mẫu TRƯỚC / SAU thao tác — để thấy ngay kết quả khi kết hợp nhiều thiết lập.
        bulkAfter(iso) {
            const b = this.bulk, n = this.nightInfo(iso), ids = this.slotPromoIds(n.slot);
            const kindOf = id => this.isUp(this.promoMeta[id]) ? 'surcharge' : 'discount';
            if (b.mode==='add') {
                if (b.action==='price')  return this.calcFinal(b.price!=='' ? parseInt(b.price,10) : n.base, ids, iso);
                if (b.action==='promo')  { const add = b.promoMode==='custom' ? [-1] : b.promos; return this.calcFinal(n.base, b.replace ? add : [...new Set([...ids, ...add])], iso); }
                return n.fin;
            }
            if (b.action==='reset') return this.basePrice;
            if (b.action==='price') return this.calcFinal(this.basePrice, ids, iso);
            if (b.action==='promo') {
                const keep = b.promos.length ? ids.filter(id => !b.promos.includes(id)) : ids.filter(id => b.removeKind!=='all' && kindOf(id)!==b.removeKind);
                return this.calcFinal(n.base, keep, iso);
            }
            return n.fin;
        },
        get bulkSamples() { return this.bulkDates.slice(0, 3).map(iso => ({ iso, before: this.nightInfo(iso).fin, after: this.bulkAfter(iso) })); },
        get bulkCanSubmit() {
            const b = this.bulk;
            if (b.busy || !this.bulkDates.length || this.bulkTooLong) return false;
            if (b.mode==='add' && b.action==='price')  return b.price!=='';
            if (b.mode==='add' && b.action==='promo')  return b.promoMode==='custom' ? this.quickValid(b.cUnit, b.cValue) : b.promos.length > 0;
            if (b.mode==='add' && b.action==='coupon') return b.coupons.length > 0;
            return true;
        },
        get bulkSubmitLabel() {
            const b = this.bulk, n = this.bulkDates.length;
            const promoVerb = b.mode==='add' && b.action==='promo' && b.promoMode==='custom' && this.quickValid(b.cUnit, b.cValue) ? this.quickLabel(b.cDir, b.cUnit, b.cValue) : (b.replace ? 'Thay KM' : 'Gắn KM');
            const verb = b.mode==='add' ? ({price:'Ghi đè giá', promo: promoVerb, coupon:'Gắn mã'})[b.action] : ({reset:'Về giá gốc', price:'Gỡ giá ghi đè', promo:'Gỡ KM', coupon:'Gỡ mã'})[b.action];
            return n ? verb+' · '+n+' ngày' : verb;
        },
        async submitBulk() {
            if (!this.bulkCanSubmit) return;
            const b = this.bulk, wd = [...b.weekdays], n = this.bulkDates.length;
            if (b.mode==='remove' && b.action==='reset' && !confirm('Xoá MỌI thiết lập (giá ghi đè, KM, phụ thu, mã) của '+n+' ngày? Các ngày này về giá gốc.')) return;
            b.busy = true;
            if (b.mode==='add') {
                if (b.action==='price')       await $wire.call('applyBulkOverride', this.roomId, b.from, b.to, parseInt(b.price,10), b.checkin || null, b.checkout || null, wd);
                else if (b.action==='promo') {
                    let ids = [...b.promos];
                    if (b.promoMode==='custom') {
                        // Tạo / dùng lại KM đúng giá trị vừa nhập, rồi gắn như KM có sẵn.
                        const opt = await $wire.call('createQuickPromotion', this.roomId, this.quickType(b.cDir, b.cUnit), parseInt(b.cValue,10), b.from, b.to);
                        if (!opt) { b.busy = false; return; }
                        this.addOption(opt);
                        ids = [opt.id];
                    }
                    await $wire.call('applyBulkPromotion', this.roomId, b.from, b.to, ids, wd, b.replace);
                }
                else                          await $wire.call('applyBulkCoupon', this.roomId, b.from, b.to, [...b.coupons], wd, b.replace);
            } else {
                if (b.action==='reset')       await $wire.call('removeBulkAll', this.roomId, b.from, b.to, wd);
                else if (b.action==='price')  await $wire.call('removeBulkOverride', this.roomId, b.from, b.to, wd);
                else if (b.action==='promo')  await $wire.call('removeBulkPromotion', this.roomId, b.from, b.to, [...b.promos], wd, b.promos.length ? null : (b.removeKind==='all' ? null : b.removeKind));
                else                          await $wire.call('removeBulkCoupon', this.roomId, b.from, b.to, [...b.coupons], wd);
            }
            b.busy = false; this.closeBulk();
        },
    }"
    class="w-full"
>
    {{-- Thanh công cụ --}}
    <div class="dp-toolbar">
        <div class="dp-month">
            <button type="button" @click="prevMonth()" class="dp-icon-btn" aria-label="Tháng trước">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <span class="dp-month__label" x-text="monthLabel"></span>
            <button type="button" @click="nextMonth()" class="dp-icon-btn" aria-label="Tháng sau">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
        <span class="dp-base">Giá gốc <strong x-text="formatPrice(basePrice)"></strong>/đêm · nhận <span x-text="defaultCheckin"></span> · trả <span x-text="defaultCheckout"></span></span>
        <div class="dp-toolbar__actions">
            <button type="button" class="dp-btn dp-btn--primary" @click="openBulk('add')">Thiết lập hàng loạt</button>
            <button type="button" class="dp-btn dp-btn--ghost" @click="openBulk('remove')">Gỡ / về giá gốc</button>
        </div>
    </div>

    {{-- Lịch tháng: ô hiện GIÁ CUỐI khách trả cho đêm đó, gạch giá trước KM nếu khác --}}
    <div class="grid grid-cols-7 mb-1">
        <template x-for="w in ['T2','T3','T4','T5','T6','T7','CN']">
            <div class="text-center text-[10px] font-bold text-gray-400 py-1 uppercase tracking-widest" x-text="w"></div>
        </template>
    </div>
    <div class="grid grid-cols-7 gap-1">
        <template x-for="n in firstDayOffset"><div></div></template>
        <template x-for="d in daysInMonth" :key="d">
            <button type="button" @click="openDay(d)" :disabled="isPast(d)" class="cal-cell focus:outline-none" :class="cellClass(d)"
                    :title="(()=>{const n=nightInfo(isoDate(d));return (n.override?'Giá ghi đè ':'Giá gốc ')+formatPrice(n.base)+(n.fin!==n.base?' → còn '+formatPrice(n.fin):'');})()">
                <span class="cal-cell__day" x-text="d"></span>
                <span :class="cellPriceClass(isoDate(d))" x-text="formatShort(nightInfo(isoDate(d)).fin)"></span>
                <span class="cal-price-old" x-show="nightInfo(isoDate(d)).fin !== nightInfo(isoDate(d)).base" x-text="formatShort(nightInfo(isoDate(d)).base)"></span>
                <div class="cal-indicator-wrapper">
                    <span x-show="getSlot(isoDate(d))?.promotions?.length > 0" class="cal-promo-line"></span>
                    <span x-show="getSlot(isoDate(d))?.surcharges?.length > 0" class="cal-surcharge-line"></span>
                    <span x-show="getSlot(isoDate(d))?.coupons?.length > 0" class="cal-coupon-line"></span>
                </div>
            </button>
        </template>
    </div>

    {{-- Chú thích --}}
    <div class="dp-legend">
        <span><i style="background:rgb(52 211 153 / 0.35)"></i>Giá ghi đè</span>
        <span><i class="dp-legend__line" style="background:#f97316"></i>Khuyến mãi</span>
        <span><i class="dp-legend__line" style="background:#eab308"></i>Phụ thu</span>
        <span><i class="dp-legend__line" style="background:#ef4444"></i>Mã giảm giá</span>
        <span class="dp-legend__count">
            <strong x-text="(slots||[]).length"></strong> ngày đặc biệt
            <button type="button" x-show="slots&&slots.length>0" class="dp-link-danger"
                    @click.prevent="if(confirm('Xoá TẤT CẢ ngày đặc biệt của phòng này (giá ghi đè, KM, phụ thu, mã)? Mọi ngày sẽ về giá gốc.')){await $wire.call('deleteAllDates', roomId);}">Xoá tất cả</button>
        </span>
    </div>

    @include('book::filament.forms.components.partials._edit-modal')
    @include('book::filament.forms.components.partials._action-modal')
</div>
