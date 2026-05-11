{{-- ══════════════════════════════════
     UNIFIED ACTION MODAL (3 Tabs)
══════════════════════════════════ --}}
<div x-show="showActionModal"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display:none"
     @keydown.escape.window="closeActionModal()">

    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeActionModal()"></div>

    <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:92vh"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-3" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

        {{-- Header: icon + title + close + segmented tabs --}}
        <div style="flex-shrink:0">
            {{-- Top row --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px 14px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div class="bk-modal-icon"
                         :class="actionTab==='override'?'bk-modal-icon--primary':actionTab==='promo'?'bk-modal-icon--amber':actionTab==='surcharge'?'bk-modal-icon--orange':'bk-modal-icon--emerald'">
                        <svg x-show="actionTab==='override'" style="width:18px;height:18px;color:rgb(var(--color-primary-600))" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <svg x-show="actionTab==='promo'" style="width:18px;height:18px;color:#b45309" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <svg x-show="actionTab==='surcharge'" style="width:18px;height:18px;color:#ea580c" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 7h6v6"/>
                        </svg>
                        <svg x-show="actionTab==='coupon'" style="width:18px;height:18px;color:#065f46" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                        </svg>
                    </div>
                    <div>
                        <p style="font-size:15px;font-weight:700;color:#111827;line-height:1.25"
                           x-text="actionTab==='override'?'Ghi đè giá hàng loạt':actionTab==='promo'?'Áp khuyến mãi hàng loạt':actionTab==='surcharge'?'Áp phụ thu hàng loạt':'Áp mã giảm giá hàng loạt'"></p>
                        <p style="font-size:12px;color:#9ca3af;margin-top:2px"
                           x-text="actionTab==='override'?'Đặt giá cố định cho nhiều ngày':actionTab==='promo'?'Gắn khuyến mãi giảm giá theo ngày':actionTab==='surcharge'?'Gắn phụ thu tăng giá theo ngày':'Gắn mã giảm giá theo khoảng ngày'"></p>
                    </div>
                </div>
                <button type="button" @click="closeActionModal()" class="bk-nav-btn" style="flex-shrink:0">
                    <svg style="width:15px;height:15px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            {{-- Segmented tab control --}}
            <div style="padding:0 16px 16px">
                <div class="bk-tab-rail">
                    <button type="button" @click="actionTab='override'"
                            class="bk-tab-btn"
                            :class="actionTab==='override'?'bk-tab-btn--primary':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Ghi đè
                    </button>
                    <button type="button" @click="actionTab='promo'"
                            class="bk-tab-btn"
                            :class="actionTab==='promo'?'bk-tab-btn--amber':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        KM
                    </button>
                    <button type="button" @click="actionTab='surcharge'"
                            class="bk-tab-btn"
                            :class="actionTab==='surcharge'?'bk-tab-btn--orange':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline stroke-linecap="round" stroke-linejoin="round" points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline stroke-linecap="round" stroke-linejoin="round" points="16 7 22 7 22 13"/></svg>
                        Phụ thu
                    </button>
                    <button type="button" @click="actionTab='coupon'"
                            class="bk-tab-btn"
                            :class="actionTab==='coupon'?'bk-tab-btn--emerald':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                        Mã GG
                    </button>
                </div>
            </div>
            <div style="height:1px;background:#f3f4f6"></div>
        </div>

        {{-- Body --}}
        <div style="overflow-y:auto;flex:1;padding:20px;display:flex;flex-direction:column;gap:18px">

            {{-- [Ghi đè tab] Giá --}}
            <div x-show="actionTab==='override'">
                <p class="bk-section-label">Giá ghi đè (VNĐ / đêm)</p>
                <div style="position:relative">
                    <input type="text" inputmode="numeric" x-model="bulkPrice"
                           @input="bulkPrice=$event.target.value.replace(/[^0-9]/g,'')"
                           placeholder="Nhập số tiền..."
                           style="width:100%;border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 48px 9px 14px;font-size:14px;outline:none;transition:border-color 0.15s,box-shadow 0.15s;background:#fff"
                           onfocus="this.style.borderColor='rgb(var(--color-primary-600))';this.style.boxShadow='0 0 0 3px rgb(var(--color-primary-600) / 0.12)'"
                           onblur="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'"/>
                    <span style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:600;color:#9ca3af">VNĐ</span>
                </div>
                <div x-show="bulkPrice" style="margin-top:5px;font-size:12px;color:#6b7280;display:flex;gap:4px;align-items:center">
                    <span style="font-weight:700;color:rgb(var(--color-primary-700))" x-text="bulkPriceDisplay"></span>
                    <span x-show="nightsCount>0">× <span x-text="nightsCount"></span> đêm = <strong style="color:#111827" x-text="totalPrice"></strong></span>
                </div>
            </div>

            {{-- Khoảng ngày (shared across all tabs) --}}
            <div>
                <p class="bk-section-label">Khoảng ngày áp dụng</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <div>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Từ ngày</p>
                        <button type="button" @click="openCalendar('start')" class="bk-date-btn" :class="activeCalendar==='start'?'is-open':''">
                            <span style="font-size:13px;font-weight:500" :style="!startDate?'color:#d1d5db':''" x-text="startDate?formatDate(startDate):'DD/MM/YYYY'"></span>
                            <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <div>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Đến ngày</p>
                        <button type="button" @click="openCalendar('end')" class="bk-date-btn" :class="activeCalendar==='end'?'is-open':''">
                            <span style="font-size:13px;font-weight:500" :style="!endDate?'color:#d1d5db':''" x-text="endDate?formatDate(endDate):'DD/MM/YYYY'"></span>
                            <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                </div>

                <div x-show="nightsCount>0" style="margin-top:8px">
                    <span style="display:inline-flex;align-items:center;gap:4px;background:rgb(var(--color-primary-600) / 0.08);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;color:rgb(var(--color-primary-700))">
                        <svg style="width:11px;height:11px;stroke:rgb(var(--color-primary-600))" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        <span x-text="nightsCount+' đêm'"></span>
                    </span>
                </div>

                {{-- Inline calendar --}}
                <div x-show="activeCalendar"
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     style="margin-top:10px;border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,0.08)">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f3f4f6">
                        <button type="button" @click="calPrev()" class="bk-nav-btn">
                            <svg style="width:13px;height:13px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span style="font-size:13px;font-weight:700;color:#111827;text-transform:capitalize" x-text="calMonthLabel"></span>
                        <button type="button" @click="calNext()" class="bk-nav-btn">
                            <svg style="width:13px;height:13px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);padding:6px 8px 2px">
                        <template x-for="day in ['T2','T3','T4','T5','T6','T7','CN']">
                            <div style="text-align:center;font-size:9px;font-weight:700;color:#9ca3af;padding:2px 0;text-transform:uppercase;letter-spacing:0.05em" x-text="day"></div>
                        </template>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);padding:0 8px 10px">
                        <template x-for="n in calFirstDayOffset"><div style="height:36px"></div></template>
                        <template x-for="d in calDaysInMonth" :key="d">
                            <div style="position:relative;height:36px;display:flex;align-items:center;justify-content:center">
                                <div class="bk-strip"
                                     :class="{'bk-strip-start':isCalStripStart(d),'bk-strip-end':isCalStripEnd(d),'bk-strip-full':isCalInRange(d)}"
                                     x-show="isCalStripStart(d)||isCalStripEnd(d)||isCalInRange(d)"></div>
                                <button type="button" @click="selectCalDate(d)" class="bk-day-btn"
                                        :class="{'bk-day-endpoint':isCalEndpoint(d),'bk-day-other':isCalOther(d),'bk-day-in-range':isCalInRange(d)&&!isCalEndpoint(d)&&!isCalOther(d)}"
                                        x-text="d"></button>
                            </div>
                        </template>
                    </div>
                    <p style="text-align:center;font-size:11px;color:#9ca3af;padding:0 14px 10px">
                        <span x-show="activeCalendar==='start'">Chọn ngày bắt đầu</span>
                        <span x-show="activeCalendar==='end'">Chọn ngày kết thúc</span>
                    </p>
                </div>
            </div>

            {{-- [Ghi đè tab] Giờ nhận & trả phòng --}}
            <div x-show="actionTab==='override'">
                <p class="bk-section-label">Giờ nhận & trả phòng</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">

                    {{-- Checkin --}}
                    <div style="position:relative" x-data>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Checkin <span style="color:#9ca3af;font-weight:400">(giờ vào)</span></p>
                        <div class="bk-time-display" :class="activeTimePicker==='checkin'?'is-open':''" @click="toggleTimePicker('checkin')">
                            <span class="bk-time-value" x-text="checkinTimeStr"></span>
                            <svg style="width:14px;height:14px;color:#9ca3af" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div x-show="activeTimePicker==='checkin'"
                             @click.outside="activeTimePicker=null"
                             class="bk-time-panel" style="position:absolute;left:0;right:0;bottom:100%;top:auto;z-index:9999;margin-bottom:4px">
                            <div style="padding:14px 12px;display:flex;flex-direction:column;gap:12px">
                                <div class="bk-time-spinbox">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="checkinHour=clampHour(checkinHour+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="23" class="bk-spin-input"
                                               :value="String(checkinHour).padStart(2,'0')"
                                               @change="checkinHour=clampHour($event.target.value)"
                                               @input="checkinHour=clampHour($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="checkinHour=clampHour(checkinHour-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                    <span class="bk-spin-sep">:</span>
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="checkinMinute=clampMinute(checkinMinute+1>=60?0:checkinMinute+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="59" class="bk-spin-input"
                                               :value="String(checkinMinute).padStart(2,'0')"
                                               @change="checkinMinute=clampMinute($event.target.value)"
                                               @input="checkinMinute=clampMinute($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="checkinMinute=clampMinute(checkinMinute-1<0?59:checkinMinute-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Checkout --}}
                    <div style="position:relative" x-data>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Checkout <span style="color:#9ca3af;font-weight:400">(giờ ra)</span></p>
                        <div class="bk-time-display" :class="activeTimePicker==='checkout'?'is-open':''" @click="toggleTimePicker('checkout')">
                            <span class="bk-time-value" x-text="checkoutTimeStr"></span>
                            <svg style="width:14px;height:14px;color:#9ca3af" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div x-show="activeTimePicker==='checkout'"
                             @click.outside="activeTimePicker=null"
                             class="bk-time-panel" style="position:absolute;left:0;right:0;bottom:100%;top:auto;z-index:9999;margin-bottom:4px">
                            <div style="padding:14px 12px;display:flex;flex-direction:column;gap:12px">
                                <div class="bk-time-spinbox">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="checkoutHour=clampHour(checkoutHour+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="23" class="bk-spin-input"
                                               :value="String(checkoutHour).padStart(2,'0')"
                                               @change="checkoutHour=clampHour($event.target.value)"
                                               @input="checkoutHour=clampHour($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="checkoutHour=clampHour(checkoutHour-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                    <span class="bk-spin-sep">:</span>
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="checkoutMinute=clampMinute(checkoutMinute+1>=60?0:checkoutMinute+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="59" class="bk-spin-input"
                                               :value="String(checkoutMinute).padStart(2,'0')"
                                               @change="checkoutMinute=clampMinute($event.target.value)"
                                               @input="checkoutMinute=clampMinute($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="checkoutMinute=clampMinute(checkoutMinute-1<0?59:checkoutMinute-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {{-- [Áp giảm giá tab] Promo list --}}
            <div x-show="actionTab==='promo'">
                @if(!empty($promotionDiscountOptions))
                <p class="bk-section-label">Chọn khuyến mãi</p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($promotionDiscountOptions as $promoId => $promoName)
                        <div class="bk-sel-row"
                             :class="selectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-row--amber' : ''"
                             @click="togglePromoSel({{ (int)$promoId }})">
                            <div class="bk-sel-check"
                                 :class="selectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-check--amber' : ''">
                                <svg x-show="selectedPromos.includes({{ (int)$promoId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label"
                                  :class="selectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-label--amber' : ''">{{ $promoName }}</span>
                            <span class="bk-sel-badge bk-sel-badge--amber"
                                  x-show="selectedPromos.includes({{ (int)$promoId }})">✓</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="selectedPromos.length>0">
                    Đã chọn <strong x-text="selectedPromos.length"></strong> khuyến mãi
                </p>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:12px">
                    <svg style="width:14px;height:14px;color:#d97706;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#92400e;line-height:1.5">Khuyến mãi sẽ tính trên <strong>giá ghi đè</strong> (nếu có) hoặc <strong>giá gốc</strong> nếu ngày chưa có giá ghi đè.</p>
                </div>
                @else
                <div class="bk-sel-empty">Chưa có khuyến mãi (giảm giá) nào được tạo.</div>
                @endif
            </div>

            {{-- [Áp phụ thu tab] Surcharge promo list --}}
            <div x-show="actionTab==='surcharge'">
                @if(!empty($promotionSurchargeOptions))
                <p class="bk-section-label">Chọn phụ thu</p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($promotionSurchargeOptions as $promoId => $promoName)
                        <div class="bk-sel-row"
                             :class="selectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-row--yellow' : ''"
                             @click="toggleSurchargeSel({{ (int)$promoId }})">
                            <div class="bk-sel-check"
                                 :class="selectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-check--yellow' : ''">
                                <svg x-show="selectedSurcharges.includes({{ (int)$promoId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label"
                                  :class="selectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-label--yellow' : ''">{{ $promoName }}</span>
                            <span class="bk-sel-badge bk-sel-badge--yellow"
                                  x-show="selectedSurcharges.includes({{ (int)$promoId }})">✓</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="selectedSurcharges.length>0">
                    Đã chọn <strong x-text="selectedSurcharges.length"></strong> phụ thu
                </p>
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:12px">
                    <svg style="width:14px;height:14px;color:#ea580c;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#9a3412;line-height:1.5">Phụ thu sẽ <strong>tăng giá</strong> tính trên <strong>giá ghi đè</strong> (nếu có) hoặc <strong>giá gốc</strong> nếu ngày chưa có giá ghi đè.</p>
                </div>
                @else
                <div class="bk-sel-empty">Chưa có phụ thu nào được tạo.</div>
                @endif
            </div>

            {{-- [Áp mã giảm giá tab] Coupon list --}}
            <div x-show="actionTab==='coupon'">
                @if(!empty($couponOptions))
                <p class="bk-section-label">Chọn mã giảm giá</p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($couponOptions as $couponId => $couponName)
                        <div class="bk-sel-row"
                             :class="selectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-row--emerald' : ''"
                             @click="toggleCouponSel({{ (int)$couponId }})">
                            <div class="bk-sel-check"
                                 :class="selectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-check--emerald' : ''">
                                <svg x-show="selectedCoupons.includes({{ (int)$couponId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label"
                                  :class="selectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-label--emerald' : ''">{{ $couponName }}</span>
                            <span class="bk-sel-badge bk-sel-badge--emerald"
                                  x-show="selectedCoupons.includes({{ (int)$couponId }})">✓</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="selectedCoupons.length>0">
                    Đã chọn <strong x-text="selectedCoupons.length"></strong> mã giảm giá
                </p>
                @else
                <div class="bk-sel-empty">Chưa có mã giảm giá nào đang hoạt động.</div>
                @endif
            </div>

        </div>{{-- end body --}}

        {{-- Footer --}}
        <div style="display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f3f4f6;background:#fafafa;flex-shrink:0">
            <button type="button" @click="closeActionModal()" class="bk-btn-cancel" :disabled="applyingBulk">
                Hủy
            </button>
            <button type="button"
                    :disabled="applyingBulk||(actionTab==='override'&&!canApply)||(actionTab==='promo'&&!canApplyPromo)||(actionTab==='surcharge'&&!canApplySurcharge)||(actionTab==='coupon'&&!canApplyCoupon)"
                    @click="actionTab==='override'?applyBulk():(actionTab==='promo'?applyPromos():(actionTab==='surcharge'?applySurcharges():applyCoupons()))"
                    class="bk-btn-primary"
                :style="(applyingBulk||(actionTab==='override'&&!canApply)||(actionTab==='promo'&&!canApplyPromo)||(actionTab==='surcharge'&&!canApplySurcharge)||(actionTab==='coupon'&&!canApplyCoupon))?'background:#d1d5db;box-shadow:none;cursor:not-allowed':actionTab==='promo'?'background:#d97706;box-shadow:0 2px 8px rgba(217,119,6,.3)':actionTab==='surcharge'?'background:#ea580c;box-shadow:0 2px 8px rgba(234,88,12,.3)':actionTab==='coupon'?'background:#059669;box-shadow:0 2px 8px rgba(5,150,105,.3)':'background:rgb(var(--primary-600));box-shadow:0 2px 8px rgb(var(--primary-600) / 0.35)'">
                <svg x-show="applyingBulk" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg x-show="!applyingBulk" style="width:14px;height:14px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <span x-text="applyingBulk ? 'Đang áp dụng...' : (nightsCount>0?('Áp dụng '+nightsCount+' đêm'):'Áp dụng')"></span>
            </button>
        </div>

    </div>
</div>
