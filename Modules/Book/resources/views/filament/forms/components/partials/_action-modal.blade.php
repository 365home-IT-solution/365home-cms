{{-- ══════════════════════════════════
     POPUP HÀNG LOẠT — gộp "thiết lập" và "gỡ" vào 1 chỗ, đi theo 3 bước từ trên xuống:
       1. Ngày nào (khoảng + thứ)   2. Làm gì   3. Chi tiết của thao tác
     Chân popup luôn hiện số ngày khớp + giá TRƯỚC → SAU của vài ngày mẫu.
══════════════════════════════════ --}}
<div x-show="bulk.open" class="dp-overlay" style="display:none" @keydown.escape.window="closeBulk()"
     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
    <div class="dp-backdrop" @click="closeBulk()"></div>

    <div class="dp-sheet" role="dialog" aria-modal="true">
        <div class="dp-sheet__head">
            <div class="dp-seg">
                <button type="button" :class="bulk.mode==='add' ? 'is-on' : ''" @click="setMode('add')">Thiết lập</button>
                <button type="button" :class="bulk.mode==='remove' ? 'is-on is-danger' : ''" @click="setMode('remove')">Gỡ / về giá gốc</button>
            </div>
            <button type="button" class="dp-icon-btn" @click="closeBulk()" aria-label="Đóng">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="dp-sheet__body">
            {{-- 1. Ngày nào --}}
            <div>
                <p class="dp-section"><span class="dp-num">1</span> Áp dụng cho ngày nào</p>
                <div class="dp-row2">
                    <label class="dp-field"><span class="dp-field__label">Từ ngày</span>
                        <input type="date" class="dp-input" x-model="bulk.from" :min="bulk.mode==='add' ? todayIso : null"></label>
                    <label class="dp-field"><span class="dp-field__label">Đến ngày <small>(tính cả ngày này)</small></span>
                        <input type="date" class="dp-input" x-model="bulk.to" :min="bulk.from || null"></label>
                </div>
                <div class="dp-presets">
                    <button type="button" @click="setRange('7d')">7 ngày tới</button>
                    <button type="button" @click="setRange('30d')">30 ngày tới</button>
                    <button type="button" @click="setRange('month')">Hết tháng này</button>
                    <button type="button" @click="setRange('next')">Tháng sau</button>
                    <button type="button" @click="setRange('year')">Hết năm nay</button>
                    <button type="button" @click="bulk.to = bulk.from">Chỉ 1 ngày</button>
                </div>
                <div class="dp-weekdays">
                    <span class="dp-field__label">Chỉ các thứ</span>
                    <template x-for="w in weekdayNames" :key="w[0]">
                        <button type="button" class="bk-wd-chip" :class="bulk.weekdays.includes(w[0]) ? 'bk-wd-chip--on' : ''" @click="toggle(bulk.weekdays, w[0])" x-text="w[1]"></button>
                    </template>
                    <button type="button" class="bk-wd-preset" @click="bulk.weekdays.splice(0, 7, 1,2,3,4)">T2–T5</button>
                    <button type="button" class="bk-wd-preset" @click="bulk.weekdays.splice(0, 7, 5,6,7)">T6–CN</button>
                    <button type="button" class="bk-wd-preset" x-show="bulk.weekdays.length" @click="bulk.weekdays.splice(0, 7)">Mọi ngày</button>
                </div>
            </div>

            {{-- 2. Làm gì --}}
            <div>
                <p class="dp-section"><span class="dp-num">2</span> <span x-text="bulk.mode==='add' ? 'Thiết lập gì' : 'Gỡ gì'"></span></p>
                <div class="dp-actions" x-show="bulk.mode==='add'">
                    <button type="button" :class="bulk.action==='promo' ? 'is-on' : ''" @click="bulk.action='promo'"><b>KM / phụ thu</b><span>Giảm hoặc tăng theo %, số tiền</span></button>
                    <button type="button" :class="bulk.action==='price' ? 'is-on' : ''" @click="bulk.action='price'"><b>Giá cố định</b><span>Ghi đè giá gốc, giờ nhận/trả</span></button>
                    <button type="button" :class="bulk.action==='coupon' ? 'is-on' : ''" @click="bulk.action='coupon'"><b>Mã giảm giá</b><span>Mã chỉ dùng được các đêm này</span></button>
                </div>
                <div class="dp-actions" x-show="bulk.mode==='remove'">
                    <button type="button" :class="bulk.action==='reset' ? 'is-on is-danger' : ''" @click="bulk.action='reset'"><b>Về giá gốc</b><span>Xoá mọi thiết lập (vd ngày lễ)</span></button>
                    <button type="button" :class="bulk.action==='promo' ? 'is-on is-danger' : ''" @click="bulk.action='promo'"><b>KM / phụ thu</b><span>Giữ giá ghi đè và mã</span></button>
                    <button type="button" :class="bulk.action==='price' ? 'is-on is-danger' : ''" @click="bulk.action='price'"><b>Giá ghi đè</b><span>Giữ KM, phụ thu và mã</span></button>
                    <button type="button" :class="bulk.action==='coupon' ? 'is-on is-danger' : ''" @click="bulk.action='coupon'"><b>Mã giảm giá</b><span>Giữ giá và KM</span></button>
                </div>
            </div>

            {{-- 3. Chi tiết --}}
            <div x-show="!(bulk.mode==='remove' && bulk.action==='reset')">
                <p class="dp-section"><span class="dp-num">3</span> Chi tiết</p>

                {{-- Giá cố định --}}
                <div x-show="bulk.mode==='add' && bulk.action==='price'" class="dp-row3">
                    <label class="dp-field"><span class="dp-field__label">Giá / đêm</span>
                        <input type="text" inputmode="numeric" class="dp-input" x-model="bulk.price" @input="bulk.price=$event.target.value.replace(/[^0-9]/g,'')" :placeholder="'Gốc '+formatPrice(basePrice)"></label>
                    <label class="dp-field"><span class="dp-field__label">Nhận phòng</span><input type="time" step="300" class="dp-input" x-model="bulk.checkin"></label>
                    <label class="dp-field"><span class="dp-field__label">Trả phòng</span><input type="time" step="300" class="dp-input" x-model="bulk.checkout"></label>
                </div>
                <p class="dp-note" x-show="bulk.mode==='add' && bulk.action==='price'">KM / phụ thu đang gắn ở các ngày này vẫn tính tiếp trên giá mới.</p>

                {{-- KM / phụ thu (thêm hoặc gỡ) --}}
                <div x-show="bulk.action==='promo'">
                    {{-- Thêm: mặc định NHẬP GIÁ TRỊ (giảm/tăng × %/số tiền); chọn KM có sẵn là tuỳ chọn --}}
                    <div x-show="bulk.mode==='add'" class="dp-seg dp-seg--sm" style="margin-bottom:10px">
                        <button type="button" :class="bulk.promoMode==='custom' ? 'is-on' : ''" @click="bulk.promoMode='custom'">Nhập giá trị</button>
                        <button type="button" :class="bulk.promoMode==='pick' ? 'is-on' : ''" @click="bulk.promoMode='pick'">Chọn KM có sẵn</button>
                    </div>
                    <div x-show="bulk.mode==='add' && bulk.promoMode==='custom'" class="dp-quick">
                        <div class="dp-seg dp-seg--sm">
                            <button type="button" :class="bulk.cDir==='down' ? 'is-on' : ''" @click="bulk.cDir='down'">Giảm</button>
                            <button type="button" :class="bulk.cDir==='up' ? 'is-on is-up' : ''" @click="bulk.cDir='up'">Tăng</button>
                        </div>
                        <input type="text" inputmode="numeric" class="dp-input dp-quick__value" x-model="bulk.cValue"
                               @input="bulk.cValue=$event.target.value.replace(/[^0-9]/g,'')" :placeholder="bulk.cUnit==='percent' ? 'vd 40' : 'vd 50000'">
                        <div class="dp-seg dp-seg--sm">
                            <button type="button" :class="bulk.cUnit==='percent' ? 'is-on' : ''" @click="bulk.cUnit='percent'">%</button>
                            <button type="button" :class="bulk.cUnit==='amount' ? 'is-on' : ''" @click="bulk.cUnit='amount'">đ</button>
                        </div>
                        <div class="dp-presets" style="margin-top:0">
                            <button type="button" @click="bulk.cDir='down'; bulk.cUnit='percent'; bulk.cValue='10'">−10%</button>
                            <button type="button" @click="bulk.cDir='down'; bulk.cUnit='percent'; bulk.cValue='20'">−20%</button>
                            <button type="button" @click="bulk.cDir='down'; bulk.cUnit='percent'; bulk.cValue='40'">−40%</button>
                            <button type="button" @click="bulk.cDir='up'; bulk.cUnit='percent'; bulk.cValue='10'">+10%</button>
                        </div>
                    </div>
                    <p class="dp-note" x-show="bulk.mode==='add' && bulk.promoMode==='custom'"
                       x-text="bulk.cUnit==='percent' && parseInt(bulk.cValue,10) > 100 ? 'Phần trăm tối đa 100%.' : (bulk.cDir==='down' ? 'Giảm trên giá đêm (ghi đè hoặc giá gốc), sau phụ thu.' : 'Tăng (phụ thu) trên giá đêm, cộng trước KM giảm.')"></p>

                    <div class="dp-chips" x-show="bulk.mode==='remove' || bulk.promoMode==='pick'">
                        <template x-for="o in [...surchargeOptions, ...promoOptions]" :key="o.id">
                            <button type="button" class="dp-chip" :class="{'dp-chip--on': bulk.promos.includes(o.id), 'dp-chip--up': isUp(o)}" @click="toggle(bulk.promos, o.id)" :title="o.label">
                                <b x-text="promoShort(o)"></b><span x-text="o.name"></span>
                            </button>
                        </template>
                        <span class="dp-note" x-show="!promoOptions.length && !surchargeOptions.length">Chưa có KM / phụ thu nào còn hạn (cần bật, có ngày bắt đầu và kết thúc).</span>
                    </div>
                    <label class="dp-switch" x-show="bulk.mode==='add'">
                        <input type="checkbox" x-model="bulk.replace">
                        <span><b>Thay thế</b> KM / phụ thu đang có của các ngày này <small>(bỏ trống = gắn thêm). Dùng cho ngày Tết: bỏ KM cuối tuần, chỉ còn +10%.</small></span>
                    </label>
                    <div class="dp-radios" x-show="bulk.mode==='remove' && !bulk.promos.length">
                        <span class="dp-field__label">Không chọn cái nào thì gỡ:</span>
                        <label><input type="radio" value="all" x-model="bulk.removeKind"> Tất cả</label>
                        <label><input type="radio" value="discount" x-model="bulk.removeKind"> Chỉ KM giảm</label>
                        <label><input type="radio" value="surcharge" x-model="bulk.removeKind"> Chỉ phụ thu</label>
                    </div>
                </div>

                {{-- Mã giảm giá (thêm hoặc gỡ) --}}
                <div x-show="bulk.action==='coupon'" class="dp-coupons">
                    <input type="search" class="dp-input dp-input--sm" placeholder="Tìm mã..." x-model="bulk.couponQ">
                    <div class="dp-coupons__list">
                        <template x-for="c in filteredCoupons(bulk.couponQ)" :key="c.id">
                            <label class="dp-check"><input type="checkbox" :checked="bulk.coupons.includes(c.id)" @change="toggle(bulk.coupons, c.id)"><span x-text="c.label"></span></label>
                        </template>
                    </div>
                    <label class="dp-switch" x-show="bulk.mode==='add'"><input type="checkbox" x-model="bulk.replace"><span><b>Thay thế</b> mã đang có của các ngày này</span></label>
                    <p class="dp-note" x-show="bulk.mode==='remove' && !bulk.coupons.length">Không chọn mã nào = gỡ tất cả mã.</p>
                </div>

                <p class="dp-note" x-show="bulk.mode==='remove' && bulk.action==='price'">Các ngày sẽ tính lại theo giá gốc, KM / phụ thu / mã vẫn giữ.</p>
            </div>
        </div>

        {{-- Chân: số ngày + xem trước giá --}}
        <div class="dp-sheet__foot dp-sheet__foot--col">
            <div class="dp-preview">
                <span class="dp-preview__count" :class="bulkDates.length ? '' : 'is-empty'"
                      x-text="bulkTooLong ? 'Khoảng ngày tối đa 400 ngày' : (bulkDates.length ? bulkDates.length+' ngày · '+wdLabel(bulk.weekdays) : (bulk.from && bulk.to ? 'Không có ngày nào khớp thứ đã chọn' : 'Chọn khoảng ngày'))"></span>
                <template x-for="s in bulkSamples" :key="s.iso">
                    <span class="dp-preview__item">
                        <span x-text="formatDate(s.iso, true)"></span>
                        <span class="dp-preview__before" x-show="s.before !== s.after" x-text="formatShort(s.before)"></span>
                        <b :class="s.after < s.before ? 'is-down' : (s.after > s.before ? 'is-up' : '')" x-text="formatShort(s.after)"></b>
                    </span>
                </template>
            </div>
            <div class="dp-sheet__foot-actions">
                <button type="button" class="dp-btn dp-btn--ghost" @click="closeBulk()">Huỷ</button>
                <button type="button" class="dp-btn" :class="bulk.mode==='add' ? 'dp-btn--primary' : 'dp-btn--danger'" :disabled="!bulkCanSubmit" @click="submitBulk()"
                        x-text="bulk.busy ? 'Đang áp dụng...' : bulkSubmitLabel"></button>
            </div>
        </div>
    </div>
</div>
