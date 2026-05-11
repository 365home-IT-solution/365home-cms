{{-- ══════════════════════════════════
     BULK REMOVE MODAL (3 tabs)
══════════════════════════════════ --}}
<div x-show="showRemoveModal"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display:none"
     @keydown.escape.window="closeRemoveModal()">

    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeRemoveModal()"></div>

    <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:92vh"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-3" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

        {{-- Header: icon + dynamic title + close + tab rail --}}
        <div style="flex-shrink:0">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 20px 14px">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:34px;height:34px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center"
                         :style="removeTab==='override'?'background:#fff5f5':removeTab==='promo'?'background:#fef3c7':removeTab==='surcharge'?'background:#fff7ed':'background:#d1fae5'">
                        <svg x-show="removeTab==='override'" style="width:17px;height:17px;color:#dc2626" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        <svg x-show="removeTab==='promo'" style="width:17px;height:17px;color:#b45309" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <svg x-show="removeTab==='surcharge'" style="width:17px;height:17px;color:#ea580c" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <polyline stroke-linecap="round" stroke-linejoin="round" points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline stroke-linecap="round" stroke-linejoin="round" points="16 7 22 7 22 13"/>
                        </svg>
                        <svg x-show="removeTab==='coupon'" style="width:17px;height:17px;color:#065f46" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>
                        </svg>
                    </div>
                    <div>
                        <p style="font-size:15px;font-weight:700;color:#111827;line-height:1.25"
                           x-text="removeTab==='override'?'Gỡ ghi đè hàng loạt':removeTab==='promo'?'Gỡ khuyến mãi hàng loạt':removeTab==='surcharge'?'Gỡ phụ thu hàng loạt':'Gỡ mã giảm giá hàng loạt'"></p>
                        <p style="font-size:12px;color:#9ca3af;margin-top:2px"
                           x-text="removeTab==='override'?'Xóa giá & checkin/checkout — giữ khuyến mãi':removeTab==='promo'?'Gỡ khuyến mãi giảm giá theo ngày':removeTab==='surcharge'?'Gỡ phụ thu tăng giá theo ngày':'Gỡ mã giảm giá theo khoảng ngày'"></p>
                    </div>
                </div>
                <button type="button" @click="closeRemoveModal()" class="bk-nav-btn" style="flex-shrink:0">
                    <svg style="width:15px;height:15px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            {{-- Segmented tab rail --}}
            <div style="padding:0 16px 16px">
                <div class="bk-tab-rail">
                    <button type="button" @click="removeTab='override'" class="bk-tab-btn"
                            :style="removeTab==='override'?'background:#dc2626;color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.3)':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Ghi đè
                    </button>
                    <button type="button" @click="removeTab='promo'" class="bk-tab-btn"
                            :class="removeTab==='promo'?'bk-tab-btn--amber':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        KM
                    </button>
                    <button type="button" @click="removeTab='surcharge'" class="bk-tab-btn"
                            :class="removeTab==='surcharge'?'bk-tab-btn--orange':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline stroke-linecap="round" stroke-linejoin="round" points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline stroke-linecap="round" stroke-linejoin="round" points="16 7 22 7 22 13"/></svg>
                        Phụ thu
                    </button>
                    <button type="button" @click="removeTab='coupon'" class="bk-tab-btn"
                            :class="removeTab==='coupon'?'bk-tab-btn--emerald':''">
                        <svg style="width:11px;height:11px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                        Mã GG
                    </button>
                </div>
            </div>
            <div style="height:1px;background:#f3f4f6"></div>
        </div>

        {{-- Body --}}
        <div style="overflow-y:auto;flex:1;padding:20px;display:flex;flex-direction:column;gap:18px">

            {{-- Date range (shared by all tabs) --}}
            <div>
                <p class="bk-section-label">Khoảng ngày cần gỡ</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <div>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Từ ngày</p>
                        <button type="button" @click="openRemoveCalendar('start')" class="bk-date-btn" :class="activeRemoveCal==='start'?'is-open':''">
                            <span style="font-size:13px;font-weight:500" :style="!removeStart?'color:#d1d5db':''" x-text="removeStart?formatDate(removeStart):'DD/MM/YYYY'"></span>
                            <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                    <div>
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Đến ngày</p>
                        <button type="button" @click="openRemoveCalendar('end')" class="bk-date-btn" :class="activeRemoveCal==='end'?'is-open':''">
                            <span style="font-size:13px;font-weight:500" :style="!removeEnd?'color:#d1d5db':''" x-text="removeEnd?formatDate(removeEnd):'DD/MM/YYYY'"></span>
                            <svg style="width:13px;height:13px;color:#9ca3af;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </button>
                    </div>
                </div>
                <div x-show="removeNights>0" style="margin-top:8px">
                    <span style="display:inline-flex;align-items:center;gap:4px;background:#fff5f5;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;color:#dc2626">
                        <svg style="width:11px;height:11px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        <span x-text="removeNights+' đêm'"></span>
                    </span>
                </div>
                {{-- Inline calendar --}}
                <div x-show="activeRemoveCal"
                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                     style="margin-top:10px;border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,0.08)">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f3f4f6">
                        <button type="button" @click="removeCalPrev()" class="bk-nav-btn">
                            <svg style="width:13px;height:13px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span style="font-size:13px;font-weight:700;color:#111827;text-transform:capitalize" x-text="removeCalLabel"></span>
                        <button type="button" @click="removeCalNext()" class="bk-nav-btn">
                            <svg style="width:13px;height:13px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);padding:6px 8px 2px">
                        <template x-for="day in ['T2','T3','T4','T5','T6','T7','CN']">
                            <div style="text-align:center;font-size:9px;font-weight:700;color:#9ca3af;padding:2px 0;text-transform:uppercase;letter-spacing:0.05em" x-text="day"></div>
                        </template>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);padding:0 8px 10px">
                        <template x-for="n in removeCalOffset"><div style="height:36px"></div></template>
                        <template x-for="d in removeCalDays" :key="d">
                            <div style="position:relative;height:36px;display:flex;align-items:center;justify-content:center">
                                <div class="bk-strip"
                                     :class="{'bk-strip-start':isRemoveStripStart(d),'bk-strip-end':isRemoveStripEnd(d),'bk-strip-full':isRemoveInRange(d)}"
                                     x-show="isRemoveStripStart(d)||isRemoveStripEnd(d)||isRemoveInRange(d)"></div>
                                <button type="button" @click="selectRemoveDate(d)" class="bk-day-btn"
                                        :class="{'bk-day-endpoint':isRemoveEndpoint(d),'bk-day-in-range':isRemoveInRange(d)&&!isRemoveEndpoint(d)}"
                                        x-text="d"></button>
                            </div>
                        </template>
                    </div>
                    <p style="text-align:center;font-size:11px;color:#9ca3af;padding:0 14px 10px">
                        <span x-show="activeRemoveCal==='start'">Chọn ngày bắt đầu</span>
                        <span x-show="activeRemoveCal==='end'">Chọn ngày kết thúc</span>
                    </p>
                </div>
            </div>

            {{-- Tab: Gỡ ghi đè — warning --}}
            <div x-show="removeTab==='override'">
                <div style="background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start">
                    <svg style="width:14px;height:14px;color:#dc2626;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p style="font-size:11px;color:#991b1b;line-height:1.5">
                        Thao tác này sẽ <strong>xóa giá ghi đè & giờ checkin/checkout</strong> của từng ngày trong khoảng.<br>
                        Ngày có <strong>khuyến mãi</strong> vẫn được giữ lại (tính theo giá gốc phòng).
                    </p>
                </div>
            </div>

            {{-- Tab: Gỡ khuyến mãi --}}
            <div x-show="removeTab==='promo'">
                @if(!empty($promotionDiscountOptions))
                <p class="bk-section-label">Chọn khuyến mãi cần gỡ <span style="font-weight:400;text-transform:none;font-size:10px">(để trống = gỡ tất cả)</span></p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($promotionDiscountOptions as $promoId => $promoName)
                        <div class="bk-sel-row"
                             :class="removeSelectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-row--amber' : ''"
                             @click="toggleRemovePromoSel({{ (int)$promoId }})">
                            <div class="bk-sel-check"
                                 :class="removeSelectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-check--amber' : ''">
                                <svg x-show="removeSelectedPromos.includes({{ (int)$promoId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label" :class="removeSelectedPromos.includes({{ (int)$promoId }}) ? 'bk-sel-label--amber' : ''">{{ $promoName }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="removeSelectedPromos.length>0">
                    Đã chọn <strong x-text="removeSelectedPromos.length"></strong> khuyến mãi
                </p>
                @else
                <div class="bk-sel-empty">Chưa có khuyến mãi nào.</div>
                @endif
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:10px">
                    <svg style="width:14px;height:14px;color:#d97706;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#92400e;line-height:1.5">Để trống = gỡ <strong>tất cả</strong> khuyến mãi. Ngày không còn dữ liệu nào sẽ được xóa khỏi lịch.</p>
                </div>
            </div>

            {{-- Tab: Gỡ phụ thu --}}
            <div x-show="removeTab==='surcharge'">
                @if(!empty($promotionSurchargeOptions))
                <p class="bk-section-label">Chọn phụ thu cần gỡ <span style="font-weight:400;text-transform:none;font-size:10px">(để trống = gỡ tất cả)</span></p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($promotionSurchargeOptions as $promoId => $promoName)
                        <div class="bk-sel-row"
                             :class="removeSelectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-row--yellow' : ''"
                             @click="toggleRemoveSurchargeSel({{ (int)$promoId }})">
                            <div class="bk-sel-check"
                                 :class="removeSelectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-check--yellow' : ''">
                                <svg x-show="removeSelectedSurcharges.includes({{ (int)$promoId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label" :class="removeSelectedSurcharges.includes({{ (int)$promoId }}) ? 'bk-sel-label--yellow' : ''">{{ $promoName }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="removeSelectedSurcharges.length>0">
                    Đã chọn <strong x-text="removeSelectedSurcharges.length"></strong> phụ thu
                </p>
                @else
                <div class="bk-sel-empty">Chưa có phụ thu nào đang hoạt động.</div>
                @endif
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:10px">
                    <svg style="width:14px;height:14px;color:#ea580c;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#9a3412;line-height:1.5">Để trống = gỡ <strong>tất cả</strong> phụ thu. Ngày không còn dữ liệu nào sẽ được xóa khỏi lịch.</p>
                </div>
            </div>

            {{-- Tab: Gỡ mã giảm giá --}}
            <div x-show="removeTab==='coupon'">
                @if(!empty($couponOptions))
                <p class="bk-section-label">Chọn mã giảm giá cần gỡ <span style="font-weight:400;text-transform:none;font-size:10px">(để trống = gỡ tất cả)</span></p>
                <div class="bk-sel-list">
                    <div class="bk-sel-list-scroll">
                        @foreach($couponOptions as $couponId => $couponName)
                        <div class="bk-sel-row"
                             :class="removeSelectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-row--emerald' : ''"
                             @click="toggleRemoveCouponSel({{ (int)$couponId }})">
                            <div class="bk-sel-check"
                                 :class="removeSelectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-check--emerald' : ''">
                                <svg x-show="removeSelectedCoupons.includes({{ (int)$couponId }})" style="width:10px;height:10px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span class="bk-sel-label" :class="removeSelectedCoupons.includes({{ (int)$couponId }}) ? 'bk-sel-label--emerald' : ''">{{ $couponName }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                <p class="bk-sel-count" x-show="removeSelectedCoupons.length>0">
                    Đã chọn <strong x-text="removeSelectedCoupons.length"></strong> mã giảm giá
                </p>
                @else
                <div class="bk-sel-empty">Chưa có mã giảm giá nào đang hoạt động.</div>
                @endif
                <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;padding:10px 12px;display:flex;gap:8px;align-items:flex-start;margin-top:10px">
                    <svg style="width:14px;height:14px;color:#059669;flex-shrink:0;margin-top:1px" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px;color:#065f46;line-height:1.5">Để trống = gỡ <strong>tất cả</strong> mã giảm giá. Ngày không còn dữ liệu nào sẽ được xóa khỏi lịch.</p>
                </div>
            </div>

        </div>{{-- end body --}}

        {{-- Footer --}}
        <div style="display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f3f4f6;background:#fafafa;flex-shrink:0">
            <button type="button" @click="closeRemoveModal()" class="bk-btn-cancel"
                    :disabled="removingBulk||removingPromo||removingCoupon">
                Hủy
            </button>

            {{-- Gỡ ghi đè --}}
            <template x-if="removeTab==='override'">
                <button type="button"
                        :disabled="!canRemove||removingBulk" @click="applyRemove()"
                        class="bk-btn-primary"
                        :class="(!canRemove||removingBulk)?'opacity-50 cursor-not-allowed':''"
                        style="background:#dc2626;box-shadow:0 2px 8px rgba(220,38,38,.3)">
                    <svg x-show="removingBulk" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <svg x-show="!removingBulk" style="width:14px;height:14px;flex-shrink:0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span x-show="removingBulk">Đang xóa...</span>
                    <span x-show="!removingBulk&&!canRemove">Gỡ ghi đè</span>
                    <span x-show="!removingBulk&&canRemove" x-text="'Gỡ '+removeNights+' đêm'"></span>
                </button>
            </template>

            {{-- Gỡ khuyến mãi --}}
            <template x-if="removeTab==='promo'">
                <button type="button"
                        :disabled="!canRemove||removingPromo" @click="applyRemovePromo()"
                        class="bk-btn-primary"
                        :class="(!canRemove||removingPromo)?'opacity-50 cursor-not-allowed':''"
                        style="background:#d97706;box-shadow:0 2px 8px rgba(217,119,6,.3)">
                    <svg x-show="removingPromo" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-show="removingPromo">Đang gỡ...</span>
                    <span x-show="!removingPromo&&!canRemove">Gỡ khuyến mãi</span>
                    <span x-show="!removingPromo&&canRemove" x-text="'Gỡ '+removeNights+' đêm'"></span>
                </button>
            </template>

            {{-- Gỡ phụ thu --}}
            <template x-if="removeTab==='surcharge'">
                <button type="button"
                        :disabled="!canRemove||removingPromo" @click="applyRemoveSurcharge()"
                        class="bk-btn-primary"
                        :class="(!canRemove||removingPromo)?'opacity-50 cursor-not-allowed':''"
                        style="background:#ea580c;box-shadow:0 2px 8px rgba(234,88,12,.3)">
                    <svg x-show="removingPromo" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-show="removingPromo">Đang gỡ...</span>
                    <span x-show="!removingPromo&&!canRemove">Gỡ phụ thu</span>
                    <span x-show="!removingPromo&&canRemove" x-text="'Gỡ '+removeNights+' đêm'"></span>
                </button>
            </template>

            {{-- Gỡ mã giảm giá --}}
            <template x-if="removeTab==='coupon'">
                <button type="button"
                        :disabled="!canRemove||removingCoupon" @click="applyRemoveCoupon()"
                        class="bk-btn-primary"
                        :class="(!canRemove||removingCoupon)?'opacity-50 cursor-not-allowed':''"
                        style="background:#059669;box-shadow:0 2px 8px rgba(5,150,105,.3)">
                    <svg x-show="removingCoupon" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-show="removingCoupon">Đang gỡ...</span>
                    <span x-show="!removingCoupon&&!canRemove">Gỡ mã giảm giá</span>
                    <span x-show="!removingCoupon&&canRemove" x-text="'Gỡ '+removeNights+' đêm'"></span>
                </button>
            </template>
        </div>

    </div>
</div>
