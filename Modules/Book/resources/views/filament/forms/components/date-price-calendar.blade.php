@php
    use Modules\Promotion\App\Models\Promotion;
    use Modules\Promotion\App\Models\Coupon;
    use Modules\DataPermission\Entities\UserBranchPermission;

    $authUser    = auth()->user();
    $branchFilter = null;

    if ($authUser && ! $authUser->isSuperAdmin()) {
        $branchIds    = UserBranchPermission::where('user_id', $authUser->id)->pluck('category_id');
        $branchFilter = UserBranchPermission::whereIn('category_id', $branchIds)->pluck('user_id');
    }

    $promotionOptions = Promotion::where('is_active', true)
        ->when($branchFilter !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('created_by')->orWhereIn('created_by', $branchFilter)))
        ->pluck('name', 'id')->all();

    // Danh sách KM (Giảm giá): percentage, fixed
    $promotionDiscountOptions = Promotion::where('is_active', true)
        ->whereIn('type', ['percentage', 'fixed'])
        ->when($branchFilter !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('created_by')->orWhereIn('created_by', $branchFilter)))
        ->pluck('name', 'id')->all();

    // Danh sách Phụ thu (Tăng giá): increase_percentage, increase_fixed
    $promotionSurchargeOptions = Promotion::where('is_active', true)
        ->whereIn('type', ['increase_percentage', 'increase_fixed'])
        ->when($branchFilter !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('created_by')->orWhereIn('created_by', $branchFilter)))
        ->pluck('name', 'id')->all();

    $couponOptions = Coupon::where('is_active', true)
        ->when($branchFilter !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('created_by')->orWhereIn('created_by', $branchFilter)))
        ->pluck('name', 'id')->all();
    $state            = $getState() ?? [];
    $statePath        = $getStatePath();
    $roomId           = $getRoomId();
@endphp

@include('book::filament.forms.components.partials._styles')

<div
    x-data="{
        slots: $wire.$entangle('{{ $statePath }}'),
        roomId: @js($roomId),
        basePrice: @js($getBasePrice()),
        viewYear:  new Date().getFullYear(),
        viewMonth: new Date().getMonth(),
        editingDate:    null,
        editId:         null,
        editPrice:      '',
        editPromotions: [],
        promoOptions:      @js($promotionDiscountOptions),
        surchargeOptions:  @js($promotionSurchargeOptions),

        todayIso: new Date().toISOString().split('T')[0],

isPast(d) {
    return this.isoDate(d) < this.todayIso;
},

        // ── Unified action modal ──
        showActionModal: false,
        actionTab:       'override',
        bulkPrice:       '',
        startDate:      null,
        endDate:        null,
        activeCalendar: null,
        calViewYear:    new Date().getFullYear(),
        calViewMonth:   new Date().getMonth(),

        // ── Time picker state ──
        activeTimePicker: null,
        checkinHour:    14,
        checkinMinute:  0,
        checkoutHour:   12,
        checkoutMinute: 0,

        // ── Single-date edit time picker ──
        editCheckinHour:    14,
        editCheckinMinute:  0,
        editCheckoutHour:   12,
        editCheckoutMinute: 0,
        activeEditTimePicker: null,

        // ── Loading states ──
        savingDate:   false,
        deletingDate: false,
        applyingBulk: false,
        removingBulk: false,

        // ── Single-date calendar ──
        get daysInMonth() { return new Date(this.viewYear, this.viewMonth+1, 0).getDate(); },
        get firstDayOffset() { const d=new Date(this.viewYear,this.viewMonth,1).getDay(); return d===0?6:d-1; },
        get monthLabel() { return new Date(this.viewYear,this.viewMonth,1).toLocaleDateString('vi-VN',{month:'long',year:'numeric'}); },
        isoDate(d) { return this.viewYear+'-'+String(this.viewMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0'); },
        getSlot(iso) { if(!this.slots) return null; return this.slots.find(s=>s.date&&String(s.date).slice(0,10)===iso)??null; },
        openDate(d) {
            const iso=this.isoDate(d);
            if(this.editingDate===iso){this.editingDate=null;return;}
            this.editingDate=iso;
            this.activeEditTimePicker=null;
            const slot=this.getSlot(iso);
            if(slot){
                this.editId=slot.id??null;
                this.editPrice=String(slot.price??'').replace(/[^0-9]/g,'');
                if(slot.checkin){const p=slot.checkin.split(':');this.editCheckinHour=parseInt(p[0],10);this.editCheckinMinute=parseInt(p[1]||0,10);}
                else{this.editCheckinHour=14;this.editCheckinMinute=0;}
                if(slot.checkout){const p=slot.checkout.split(':');this.editCheckoutHour=parseInt(p[0],10);this.editCheckoutMinute=parseInt(p[1]||0,10);}
                else{this.editCheckoutHour=12;this.editCheckoutMinute=0;}
            }
            else{
                this.editId=null;this.editPrice='';
                this.editCheckinHour=14;this.editCheckinMinute=0;
                this.editCheckoutHour=12;this.editCheckoutMinute=0;
            }
        },
        togglePromo(id) { const n=Number(id),i=this.editPromotions.indexOf(n); if(i>=0)this.editPromotions.splice(i,1); else this.editPromotions.push(n); },
        async saveDate() {
            if(!this.editingDate||this.editPrice===''||this.savingDate) return;
            this.savingDate=true;
            const checkin=String(this.editCheckinHour).padStart(2,'0')+':'+String(this.editCheckinMinute).padStart(2,'0');
            const checkout=String(this.editCheckoutHour).padStart(2,'0')+':'+String(this.editCheckoutMinute).padStart(2,'0');
            await $wire.call('saveSingleDate', this.roomId, this.editingDate, parseInt(this.editPrice), checkin, checkout);
            this.savingDate=false;
            this.editingDate=null;
            this.activeEditTimePicker=null;
        },
        async deleteDate() {
            if(!this.editingDate||this.deletingDate) return;
            this.deletingDate=true;
            await $wire.call('deleteSingleDate', this.roomId, this.editingDate);
            this.deletingDate=false;
            this.editingDate=null;
            this.activeEditTimePicker=null;
        },
        prevMonth() { if(this.viewMonth===0){this.viewMonth=11;this.viewYear--;}else this.viewMonth--; this.editingDate=null; },
        nextMonth() { if(this.viewMonth===11){this.viewMonth=0;this.viewYear++;}else this.viewMonth++; this.editingDate=null; },
        formatPrice(p) { const n=parseInt(String(p??'').replace(/[^0-9]/g,''),10); return isNaN(n)?'':n.toLocaleString('vi-VN')+'đ'; },
        parsePrice(p) { const n=parseInt(String(p??'').replace(/[^0-9]/g,''),10); return isNaN(n)?0:n; },
        isSurcharge(slot) { return slot?.price!=null && this.parsePrice(slot.price)>this.basePrice; },

        // ── Time picker ──
        get checkinTimeStr()  { return String(this.checkinHour).padStart(2,'0')+':'+String(this.checkinMinute).padStart(2,'0'); },
        get checkoutTimeStr() { return String(this.checkoutHour).padStart(2,'0')+':'+String(this.checkoutMinute).padStart(2,'0'); },
        toggleTimePicker(type) { this.activeTimePicker = this.activeTimePicker===type ? null : type; },
        clampHour(v)   { return Math.max(0, Math.min(23, parseInt(v)||0)); },
        clampMinute(v) { return Math.max(0, Math.min(59, parseInt(v)||0)); },
        setPreset(type, h, m) {
            if(type==='checkin') { this.checkinHour=h; this.checkinMinute=m; }
            else { this.checkoutHour=h; this.checkoutMinute=m; }
            this.activeTimePicker=null;
        },
        isPresetActive(type, h, m) {
            if(type==='checkin')  return this.checkinHour===h  && this.checkinMinute===m;
            return this.checkoutHour===h && this.checkoutMinute===m;
        },
        get editCheckinStr()  { return String(this.editCheckinHour).padStart(2,'0')+':'+String(this.editCheckinMinute).padStart(2,'0'); },
        get editCheckoutStr() { return String(this.editCheckoutHour).padStart(2,'0')+':'+String(this.editCheckoutMinute).padStart(2,'0'); },
        toggleEditTimePicker(type) { this.activeEditTimePicker = this.activeEditTimePicker===type ? null : type; },

        // ── Bulk range calendar ──
        get calDaysInMonth() { return new Date(this.calViewYear,this.calViewMonth+1,0).getDate(); },
        get calFirstDayOffset() { const d=new Date(this.calViewYear,this.calViewMonth,1).getDay(); return d===0?6:d-1; },
        get calMonthLabel() { return new Date(this.calViewYear,this.calViewMonth,1).toLocaleDateString('vi-VN',{month:'long',year:'numeric'}); },
        calIsoDate(d) { return this.calViewYear+'-'+String(this.calViewMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0'); },
        calPrev() { if(this.calViewMonth===0){this.calViewMonth=11;this.calViewYear--;}else this.calViewMonth--; },
        calNext() { if(this.calViewMonth===11){this.calViewMonth=0;this.calViewYear++;}else this.calViewMonth++; },
        openCalendar(type) {
            if(this.activeCalendar===type){this.activeCalendar=null;return;}
            this.activeCalendar=type;
            const ref=type==='start'?this.startDate:this.endDate;
            if(ref){const dt=new Date(ref+'T00:00:00');this.calViewYear=dt.getFullYear();this.calViewMonth=dt.getMonth();}
            else{this.calViewYear=new Date().getFullYear();this.calViewMonth=new Date().getMonth();}
        },
        selectCalDate(d) {
            const iso=this.calIsoDate(d);
            if(this.activeCalendar==='start'){this.startDate=iso; if(this.endDate&&iso>=this.endDate)this.endDate=null;}
            else{this.endDate=iso; if(this.startDate&&iso<=this.startDate)this.startDate=null;}
            this.activeCalendar=null;
        },
        isCalEndpoint(d) { const iso=this.calIsoDate(d); return (this.activeCalendar==='start'&&iso===this.startDate)||(this.activeCalendar==='end'&&iso===this.endDate); },
        isCalOther(d)    { const iso=this.calIsoDate(d); return (this.activeCalendar==='start'&&iso===this.endDate)||(this.activeCalendar==='end'&&iso===this.startDate); },
        isCalInRange(d)  { if(!this.startDate||!this.endDate)return false; const iso=this.calIsoDate(d); return iso>this.startDate&&iso<this.endDate; },
        isCalStripStart(d){ return !!this.startDate&&!!this.endDate&&this.calIsoDate(d)===this.startDate; },
        isCalStripEnd(d)  { return !!this.startDate&&!!this.endDate&&this.calIsoDate(d)===this.endDate; },
        formatDate(iso)  { if(!iso)return''; return new Date(iso+'T00:00:00').toLocaleDateString('vi-VN',{day:'2-digit',month:'2-digit',year:'numeric'}); },
        get nightsCount() { if(!this.startDate||!this.endDate)return 0; return Math.round((new Date(this.endDate)-new Date(this.startDate))/86400000); },
        get bulkPriceDisplay() { if(!this.bulkPrice)return''; return parseInt(this.bulkPrice).toLocaleString('vi-VN')+'đ'; },
        get totalPrice()  { if(!this.bulkPrice||!this.nightsCount)return''; return(parseInt(this.bulkPrice)*this.nightsCount).toLocaleString('vi-VN')+'đ'; },
        get canApply()    { return !!this.startDate && !!this.endDate && !!this.bulkPrice; },

        openActionModal(tab='override') {
            this.showActionModal=true; this.actionTab=tab; this.bulkPrice='';
            this.startDate=null; this.endDate=null; this.activeCalendar=null;
            this.activeTimePicker=null;
            this.checkinHour=14; this.checkinMinute=0;
            this.checkoutHour=12; this.checkoutMinute=0;
            this.selectedPromos=[]; this.selectedSurcharges=[]; this.selectedCoupons=[];
            this.calViewYear=new Date().getFullYear(); this.calViewMonth=new Date().getMonth();
        },
        closeActionModal() { this.showActionModal=false; this.activeCalendar=null; this.activeTimePicker=null; },

        // ── Bulk REMOVE modal ──
        showRemoveModal: false,
        removeTab: 'override',
        removingPromo: false,
        removingCoupon: false,
        removeSelectedPromos:      [],
        removeSelectedSurcharges:  [],
        removeSelectedCoupons:     [],
        removeStart: null,
        removeEnd:   null,
        activeRemoveCal: null,
        removeCalYear:  new Date().getFullYear(),
        removeCalMonth: new Date().getMonth(),
        get removeCalDays()   { return new Date(this.removeCalYear,this.removeCalMonth+1,0).getDate(); },
        get removeCalOffset() { const d=new Date(this.removeCalYear,this.removeCalMonth,1).getDay(); return d===0?6:d-1; },
        get removeCalLabel()  { return new Date(this.removeCalYear,this.removeCalMonth,1).toLocaleDateString('vi-VN',{month:'long',year:'numeric'}); },
        removeCalIso(d)       { return this.removeCalYear+'-'+String(this.removeCalMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0'); },
        removeCalPrev()       { if(this.removeCalMonth===0){this.removeCalMonth=11;this.removeCalYear--;}else this.removeCalMonth--; },
        removeCalNext()       { if(this.removeCalMonth===11){this.removeCalMonth=0;this.removeCalYear++;}else this.removeCalMonth++; },
        get removeNights()    { if(!this.removeStart||!this.removeEnd)return 0; return Math.round((new Date(this.removeEnd)-new Date(this.removeStart))/86400000); },
        get canRemove()       { return !!this.removeStart&&!!this.removeEnd&&this.removeNights>0; },
        toggleRemovePromoSel(id)      { const n=Number(id),i=this.removeSelectedPromos.indexOf(n); if(i>=0)this.removeSelectedPromos.splice(i,1); else this.removeSelectedPromos.push(n); },
        toggleRemoveSurchargeSel(id)  { const n=Number(id),i=this.removeSelectedSurcharges.indexOf(n); if(i>=0)this.removeSelectedSurcharges.splice(i,1); else this.removeSelectedSurcharges.push(n); },
        toggleRemoveCouponSel(id)     { const n=Number(id),i=this.removeSelectedCoupons.indexOf(n); if(i>=0)this.removeSelectedCoupons.splice(i,1); else this.removeSelectedCoupons.push(n); },
        openRemoveCalendar(type) {
            if(this.activeRemoveCal===type){this.activeRemoveCal=null;return;}
            this.activeRemoveCal=type;
            const ref=type==='start'?this.removeStart:this.removeEnd;
            if(ref){const dt=new Date(ref+'T00:00:00');this.removeCalYear=dt.getFullYear();this.removeCalMonth=dt.getMonth();}
            else{this.removeCalYear=new Date().getFullYear();this.removeCalMonth=new Date().getMonth();}
        },
        selectRemoveDate(d) {
            const iso=this.removeCalIso(d);
            if(this.activeRemoveCal==='start'){this.removeStart=iso;if(this.removeEnd&&iso>=this.removeEnd)this.removeEnd=null;}
            else{this.removeEnd=iso;if(this.removeStart&&iso<=this.removeStart)this.removeStart=null;}
            this.activeRemoveCal=null;
        },
        isRemoveEndpoint(d)  { const iso=this.removeCalIso(d); return iso===this.removeStart||iso===this.removeEnd; },
        isRemoveInRange(d)   { if(!this.removeStart||!this.removeEnd)return false; const iso=this.removeCalIso(d); return iso>this.removeStart&&iso<this.removeEnd; },
        isRemoveStripStart(d){ return !!this.removeStart&&!!this.removeEnd&&this.removeCalIso(d)===this.removeStart; },
        isRemoveStripEnd(d)  { return !!this.removeStart&&!!this.removeEnd&&this.removeCalIso(d)===this.removeEnd; },
        openRemoveModal()  { this.showRemoveModal=true; this.removeTab='override'; this.removeStart=null; this.removeEnd=null; this.activeRemoveCal=null; this.removeCalYear=new Date().getFullYear(); this.removeCalMonth=new Date().getMonth(); this.removeSelectedPromos=[]; this.removeSelectedSurcharges=[]; this.removeSelectedCoupons=[]; },
        closeRemoveModal() { this.showRemoveModal=false; this.activeRemoveCal=null; },
        async applyRemove() {
            if(!this.canRemove||this.removingBulk) return;
            this.removingBulk=true;
            await $wire.call('removeBulkOverride', this.roomId, this.removeStart, this.removeEnd);
            this.removingBulk=false;
            this.closeRemoveModal();
        },
        async applyRemovePromo() {
            if(!this.canRemove||this.removingPromo) return;
            this.removingPromo=true;
            await $wire.call('removeBulkPromotion', this.roomId, this.removeStart, this.removeEnd, [...this.removeSelectedPromos]);
            this.removingPromo=false;
            this.closeRemoveModal();
        },
        async applyRemoveSurcharge() {
            if(!this.canRemove||this.removingPromo) return;
            this.removingPromo=true;
            await $wire.call('removeBulkPromotion', this.roomId, this.removeStart, this.removeEnd, [...this.removeSelectedSurcharges]);
            this.removingPromo=false;
            this.closeRemoveModal();
        },
        async applyRemoveCoupon() {
            if(!this.canRemove||this.removingCoupon) return;
            this.removingCoupon=true;
            await $wire.call('removeBulkCoupon', this.roomId, this.removeStart, this.removeEnd, [...this.removeSelectedCoupons]);
            this.removingCoupon=false;
            this.closeRemoveModal();
        },

        // ── Promotion & coupon (shared via unified action modal) ──
        selectedPromos:     [],
        selectedSurcharges: [],
        selectedCoupons:    [],
        couponOptions:      @js($couponOptions),
        togglePromoSel(id)     { const n=Number(id),i=this.selectedPromos.indexOf(n); if(i>=0)this.selectedPromos.splice(i,1); else this.selectedPromos.push(n); },
        toggleSurchargeSel(id) { const n=Number(id),i=this.selectedSurcharges.indexOf(n); if(i>=0)this.selectedSurcharges.splice(i,1); else this.selectedSurcharges.push(n); },
        toggleCouponSel(id)    { const n=Number(id),i=this.selectedCoupons.indexOf(n); if(i>=0)this.selectedCoupons.splice(i,1); else this.selectedCoupons.push(n); },
        get canApplyPromo()      { return !!this.startDate&&!!this.endDate&&this.nightsCount>0&&this.selectedPromos.length>0; },
        get canApplySurcharge()  { return !!this.startDate&&!!this.endDate&&this.nightsCount>0&&this.selectedSurcharges.length>0; },
        get canApplyCoupon()     { return !!this.startDate&&!!this.endDate&&this.nightsCount>0&&this.selectedCoupons.length>0; },
        async applyPromos() {
            if(!this.canApplyPromo||this.applyingBulk) return;
            this.applyingBulk=true;
            await $wire.call('applyBulkPromotion', this.roomId, this.startDate, this.endDate, [...this.selectedPromos]);
            this.applyingBulk=false;
            this.closeActionModal();
        },
        async applySurcharges() {
            if(!this.canApplySurcharge||this.applyingBulk) return;
            this.applyingBulk=true;
            await $wire.call('applyBulkPromotion', this.roomId, this.startDate, this.endDate, [...this.selectedSurcharges]);
            this.applyingBulk=false;
            this.closeActionModal();
        },
        async applyCoupons() {
            if(!this.canApplyCoupon||this.applyingBulk) return;
            this.applyingBulk=true;
            await $wire.call('applyBulkCoupon', this.roomId, this.startDate, this.endDate, [...this.selectedCoupons]);
            this.applyingBulk=false;
            this.closeActionModal();
        },
        async applyBulk() {
            if(!this.canApply||this.applyingBulk) return;
            this.applyingBulk=true;
            await $wire.call('applyBulkOverride', this.roomId, this.startDate, this.endDate, parseInt(this.bulkPrice), this.checkinTimeStr, this.checkoutTimeStr);
            this.applyingBulk=false;
            this.closeActionModal();
        },
    }"
    class="w-full"
>
    {{-- ══════════════════════════════════
         MAIN CALENDAR (Ngày đặc biệt)
    ══════════════════════════════════ --}}

    {{-- Header --}}
    <div class="flex items-center justify-between mb-3">
        <button type="button" @click="prevMonth()"
                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
        <span class="text-sm font-bold text-gray-700 capitalize" x-text="monthLabel"></span>
        <button type="button" @click="nextMonth()"
                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
        </button>
    </div>

    {{-- Day labels --}}
    <div class="grid grid-cols-7 mb-1">
        <template x-for="day in ['T2','T3','T4','T5','T6','T7','CN']">
            <div class="text-center text-[10px] font-bold text-gray-400 py-1 uppercase tracking-widest" x-text="day"></div>
        </template>
    </div>

    {{-- Days --}}
    <div class="grid grid-cols-7 gap-1">
        <template x-for="n in firstDayOffset"><div></div></template>
        <template x-for="d in daysInMonth" :key="d">
          <button type="button" 
        @click="if(!isPast(d)) openDate(d)" 
        :disabled="isPast(d)"
        class="cal-cell focus:outline-none"
        :class="{
            'cal-cell--editing':  editingDate === isoDate(d),
            'cal-cell--has-slot': getSlot(isoDate(d))?.price != null && editingDate !== isoDate(d),
            'cal-cell--has-promo': getSlot(isoDate(d)) && getSlot(isoDate(d))?.price == null && (getSlot(isoDate(d))?.promotions?.length > 0) && editingDate !== isoDate(d),
            'cal-cell--normal':   (!getSlot(isoDate(d)) || (getSlot(isoDate(d))?.price == null && !getSlot(isoDate(d))?.promotions?.length)) && editingDate !== isoDate(d),
            /* Thêm class past ở đây */
            'cal-cell--past':     isPast(d) 
        }">
                <span class="cal-cell__day" x-text="d"></span>
                <span x-show="getSlot(isoDate(d))?.price != null && isSurcharge(getSlot(isoDate(d)))" class="cal-price-tag--surcharge"
                      x-text="formatPrice(getSlot(isoDate(d))?.price)"></span>
                <span x-show="getSlot(isoDate(d))?.price != null && !isSurcharge(getSlot(isoDate(d)))" class="cal-price-tag"
                      x-text="formatPrice(getSlot(isoDate(d))?.price)"></span>
                <span x-show="!getSlot(isoDate(d)) || getSlot(isoDate(d))?.price == null" class="cal-price-tag--base"
                      x-text="formatPrice(basePrice)"></span>
      <!-- Cập nhật class thành Line -->
<div class="cal-indicator-wrapper">
    <span x-show="getSlot(isoDate(d))?.promotions?.length > 0" class="cal-promo-line"></span>
    <span x-show="getSlot(isoDate(d))?.surcharges?.length > 0" class="cal-surcharge-line"></span>
    <span x-show="getSlot(isoDate(d))?.coupons?.length > 0" class="cal-coupon-line"></span>
</div>
            </button>
        </template>
    </div>

    @include('book::filament.forms.components.partials._edit-modal')

    {{-- ── Footer legend ── --}}
   <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-3 px-1 border-t pt-3">
        <div class="flex items-center gap-1.5">
            <span class="inline-block w-3 h-3 rounded-sm" style="background:rgb(52 211 153 / 0.35)"></span>
            <span class="text-xs text-gray-500 font-medium">Giá ghi đè</span>
        </div>
        
        <!-- Đường kẻ Khuyến mãi -->
        <div class="flex items-center gap-1.5">
            <span class="inline-block w-5 h-1 rounded-full" style="background:#f97316"></span>
            <span class="text-xs text-gray-500 font-medium">Khuyến mãi</span>
        </div>

        <!-- Đường kẻ Phụ thu -->
        <div class="flex items-center gap-1.5">
            <span class="inline-block w-5 h-1 rounded-full" style="background:#eef122"></span>
            <span class="text-xs text-gray-500 font-medium">Phụ thu</span>
        </div>

        <!-- Đường kẻ Mã khuyến mãi -->
        <div class="flex items-center gap-1.5">
            <span class="inline-block w-5 h-1 rounded-full" style="background:#ef4444"></span>
            <span class="text-xs text-gray-500 font-medium">Mã khuyến mãi</span>
        </div>


        <button type="button" @click="openActionModal('override')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-primary/40 bg-primary/5 px-2.5 py-1 text-xs font-semibold text-primary hover:bg-primary/10 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            Hàng loạt
        </button>

        <button type="button" @click="openRemoveModal()"
                class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition-all"
                style="border:1.5px dashed #fca5a5;background:#fff5f5;color:#dc2626"
                onmouseover="this.style.borderStyle='solid';this.style.background='#fee2e2'"
                onmouseout="this.style.borderStyle='dashed';this.style.background='#fff5f5'">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Gỡ hàng loạt
        </button>

        <div class="ml-auto flex items-center gap-2">
            <span class="text-xs text-gray-500">Tổng <strong x-text="(slots||[]).length"></strong> ngày đặc biệt</span>
            <button type="button" x-show="slots&&slots.length>0"
                    @click.prevent="if(confirm('Xóa tất cả ngày đặc biệt?')){await $wire.call('deleteAllDates', roomId);editingDate=null;}"
                    class="text-[11px] text-red-400 hover:text-red-600 underline">Xóa tất cả</button>
        </div>
    </div>

    @include('book::filament.forms.components.partials._action-modal')

    @include('book::filament.forms.components.partials._remove-modal')

</div>
