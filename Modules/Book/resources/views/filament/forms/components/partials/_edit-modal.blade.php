{{-- ══════════════════════════════════
     POPUP 1 NGÀY — sửa trọn thiết lập của 1 đêm: giá ghi đè, giờ nhận/trả, KM + phụ thu, mã.
     Trên cùng là GIÁ CUỐI + cách ra con số đó, đổi gì thấy ngay.
══════════════════════════════════ --}}
<div x-show="day.open" class="dp-overlay" style="display:none" @keydown.escape.window="closeDay()"
     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
    <div class="dp-backdrop" @click="closeDay()"></div>

    <div class="dp-sheet dp-sheet--sm" role="dialog" aria-modal="true">
        {{-- Header --}}
        <div class="dp-sheet__head">
            <div>
                <p class="dp-sheet__title" x-text="day.date ? new Date(day.date+'T00:00:00').toLocaleDateString('vi-VN',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'}) : ''"></p>
                <p class="dp-sheet__sub" x-text="dayHasSlot ? 'Ngày đang có thiết lập riêng' : 'Đang theo giá gốc của phòng'"></p>
            </div>
            <button type="button" class="dp-icon-btn" @click="closeDay()" aria-label="Đóng">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="dp-sheet__body">
            {{-- Giá cuối + diễn giải --}}
            <div class="dp-total" :class="dayFinal<dayBase ? 'dp-total--down' : (dayFinal>dayBase ? 'dp-total--up' : '')">
                <div>
                    <p class="dp-total__label">Khách trả / đêm <span>(chưa tính mã)</span></p>
                    <p class="dp-total__value" x-text="formatPrice(dayFinal) || '0đ'"></p>
                </div>
                <div class="dp-total__steps">
                    <span x-text="(day.price!=='' ? 'Ghi đè ' : 'Gốc ')+formatShort(dayBase)"></span>
                    <template x-for="p in dayApplied.filter(x => isUp(x))" :key="'u'+p.id"><span class="dp-step dp-step--up" x-text="promoShort(p)"></span></template>
                    <template x-for="p in dayApplied.filter(x => !isUp(x))" :key="'d'+p.id"><span class="dp-step dp-step--down" x-text="promoShort(p)"></span></template>
                </div>
            </div>

            {{-- Giá + giờ: 1 hàng --}}
            <div class="dp-row3">
                <label class="dp-field">
                    <span class="dp-field__label">Giá ghi đè</span>
                    <input type="text" inputmode="numeric" class="dp-input" x-model="day.price"
                           @input="day.price=$event.target.value.replace(/[^0-9]/g,'')" :placeholder="formatPrice(basePrice)">
                    <button type="button" class="dp-field__hint" x-show="day.price!==''" @click="day.price=''">Dùng giá gốc</button>
                </label>
                <label class="dp-field">
                    <span class="dp-field__label">Nhận phòng</span>
                    <input type="time" step="300" class="dp-input" x-model="day.checkin">
                </label>
                <label class="dp-field">
                    <span class="dp-field__label">Trả phòng <small>(hôm sau)</small></span>
                    <input type="time" step="300" class="dp-input" x-model="day.checkout">
                </label>
            </div>

            {{-- KM + phụ thu: chip bật/tắt --}}
            <div x-show="promoOptions.length || surchargeOptions.length">
                <p class="dp-section">Khuyến mãi & phụ thu <span x-show="day.promos.length" x-text="'· '+day.promos.length+' đang gắn'"></span></p>
                <div class="dp-chips">
                    <template x-for="o in [...surchargeOptions, ...promoOptions]" :key="o.id">
                        <button type="button" class="dp-chip" :class="{'dp-chip--on': day.promos.includes(o.id), 'dp-chip--up': isUp(o), 'dp-chip--off': !promoApplies(o, day.date)}"
                                @click="toggle(day.promos, o.id)" :title="o.label + (promoApplies(o, day.date) ? '' : ' — ngày này nằm ngoài hạn KM, không có tác dụng')">
                            <b x-text="promoShort(o)"></b><span x-text="o.name"></span>
                        </button>
                    </template>
                </div>
                <p class="dp-note" x-show="day.promos.filter(id => promoMeta[id] && !isUp(promoMeta[id])).length > 1">Đang gắn từ 2 KM giảm trở lên — cả hai đều bị trừ.</p>
            </div>

            {{-- Thêm nhanh KM theo giá trị cho riêng ngày này --}}
            <div>
                <p class="dp-section">Thêm KM theo giá trị</p>
                <div class="dp-quick">
                    <div class="dp-seg dp-seg--sm">
                        <button type="button" :class="day.qDir==='down' ? 'is-on' : ''" @click="day.qDir='down'">Giảm</button>
                        <button type="button" :class="day.qDir==='up' ? 'is-on is-up' : ''" @click="day.qDir='up'">Tăng</button>
                    </div>
                    <input type="text" inputmode="numeric" class="dp-input dp-quick__value" x-model="day.qValue"
                           @input="day.qValue=$event.target.value.replace(/[^0-9]/g,'')" @keydown.enter.prevent="addDayQuick()" :placeholder="day.qUnit==='percent' ? 'vd 20' : 'vd 50000'">
                    <div class="dp-seg dp-seg--sm">
                        <button type="button" :class="day.qUnit==='percent' ? 'is-on' : ''" @click="day.qUnit='percent'">%</button>
                        <button type="button" :class="day.qUnit==='amount' ? 'is-on' : ''" @click="day.qUnit='amount'">đ</button>
                    </div>
                    <button type="button" class="dp-btn dp-btn--ghost" :disabled="day.qBusy || !quickValid(day.qUnit, day.qValue)" @click="addDayQuick()"
                            x-text="day.qBusy ? '...' : 'Thêm'"></button>
                </div>
            </div>

            {{-- Mã: thu gọn, có tìm kiếm --}}
            <div x-show="couponOptions.length">
                <button type="button" class="dp-collapse" @click="day.showCoupons=!day.showCoupons">
                    <span>Mã giảm giá dùng được đêm này <b x-show="day.coupons.length" x-text="'('+day.coupons.length+')'"></b></span>
                    <svg class="w-4 h-4 transition" :class="day.showCoupons?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="day.showCoupons" class="dp-coupons">
                    <input type="search" class="dp-input dp-input--sm" placeholder="Tìm mã..." x-model="day.couponQ">
                    <div class="dp-coupons__list">
                        <template x-for="c in filteredCoupons(day.couponQ)" :key="c.id">
                            <label class="dp-check">
                                <input type="checkbox" :checked="day.coupons.includes(c.id)" @change="toggle(day.coupons, c.id)">
                                <span x-text="c.label"></span>
                            </label>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="dp-sheet__foot">
            <button type="button" class="dp-btn dp-btn--danger-ghost" x-show="dayHasSlot" @click="resetDay()" :disabled="day.busy">Về giá gốc</button>
            <span class="flex-1"></span>
            <button type="button" class="dp-btn dp-btn--ghost" @click="closeDay()">Huỷ</button>
            <button type="button" class="dp-btn dp-btn--primary" @click="saveDay()" :disabled="day.busy" x-text="day.busy ? 'Đang lưu...' : 'Lưu ngày này'"></button>
        </div>
    </div>
</div>
