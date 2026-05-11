{{-- ══════════════════════════════════
     EDIT DATE MODAL
══════════════════════════════════ --}}
<div x-show="editingDate"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="display:none"
     @keydown.escape.window="editingDate=null;activeEditTimePicker=null">

    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="editingDate=null;activeEditTimePicker=null"></div>

    <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         style="max-height:92vh"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-3" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

        {{-- Header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f3f4f6;flex-shrink:0">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:34px;height:34px;border-radius:10px;background:rgba(78,107,76,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <svg style="width:17px;height:17px;color:#4e6b4c" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div>
                    <p style="font-size:14px;font-weight:700;color:#111827;line-height:1.3"
                       x-text="editingDate ? new Date(editingDate+'T00:00:00').toLocaleDateString('vi-VN',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'}) : ''"></p>
                    <p style="font-size:11px;color:#9ca3af">Chỉnh giá & giờ cho ngày này</p>
                </div>
            </div>
            <button type="button" @click="editingDate=null;activeEditTimePicker=null" class="bk-nav-btn">
                <svg style="width:16px;height:16px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Body --}}
        <div style="padding:20px;display:flex;flex-direction:column;gap:16px;overflow-y:auto">

            {{-- Giá --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1.5">Giá / đêm (VNĐ)</label>
                <div class="relative">
                    <input type="text" inputmode="numeric" x-model="editPrice"
                           @input="editPrice=$event.target.value.replace(/[^0-9]/g,'')"
                           placeholder="Nhập số tiền..."
                           class="w-full rounded-xl border border-gray-300 pl-3 pr-20 py-2.5 text-sm focus:border-primary focus:ring-2 focus:ring-primary/30 outline-none transition"/>
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold"
                          style="color:#4e6b4c"
                          x-show="editPrice" x-text="formatPrice(editPrice)"></span>
                </div>
            </div>

            {{-- Giờ nhận & trả phòng --}}
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-2">Giờ nhận & trả phòng</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">

                    {{-- Edit Checkin --}}
                    <div style="position:relative">
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Checkin <span style="color:#9ca3af;font-weight:400">(giờ vào)</span></p>
                        <div class="bk-time-display" :class="activeEditTimePicker==='checkin'?'is-open':''" @click="toggleEditTimePicker('checkin')">
                            <span class="bk-time-value" x-text="editCheckinStr"></span>
                            <svg style="width:14px;height:14px;color:#9ca3af" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div x-show="activeEditTimePicker==='checkin'"
                             @click.outside="activeEditTimePicker=null"
                             class="bk-time-panel" style="position:absolute;left:0;right:0;bottom:100%;top:auto;z-index:9999;margin-bottom:4px">
                            <div style="padding:14px 12px;display:flex;flex-direction:column;gap:12px">
                                <div class="bk-time-spinbox">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="editCheckinHour=clampHour(editCheckinHour+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="23" class="bk-spin-input"
                                               :value="String(editCheckinHour).padStart(2,'0')"
                                               @change="editCheckinHour=clampHour($event.target.value)"
                                               @input="editCheckinHour=clampHour($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="editCheckinHour=clampHour(editCheckinHour-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                    <span class="bk-spin-sep">:</span>
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="editCheckinMinute=clampMinute(editCheckinMinute+1>=60?0:editCheckinMinute+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="59" class="bk-spin-input"
                                               :value="String(editCheckinMinute).padStart(2,'0')"
                                               @change="editCheckinMinute=clampMinute($event.target.value)"
                                               @input="editCheckinMinute=clampMinute($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="editCheckinMinute=clampMinute(editCheckinMinute-1<0?59:editCheckinMinute-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Edit Checkout --}}
                    <div style="position:relative">
                        <p style="font-size:11px;color:#6b7280;margin-bottom:4px;font-weight:500">Checkout <span style="color:#9ca3af;font-weight:400">(giờ ra)</span></p>
                        <div class="bk-time-display" :class="activeEditTimePicker==='checkout'?'is-open':''" @click="toggleEditTimePicker('checkout')">
                            <span class="bk-time-value" x-text="editCheckoutStr"></span>
                            <svg style="width:14px;height:14px;color:#9ca3af" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div x-show="activeEditTimePicker==='checkout'"
                             @click.outside="activeEditTimePicker=null"
                             class="bk-time-panel" style="position:absolute;left:0;right:0;bottom:100%;top:auto;z-index:9999;margin-bottom:4px">
                            <div style="padding:14px 12px;display:flex;flex-direction:column;gap:12px">
                                <div class="bk-time-spinbox">
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="editCheckoutHour=clampHour(editCheckoutHour+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="23" class="bk-spin-input"
                                               :value="String(editCheckoutHour).padStart(2,'0')"
                                               @change="editCheckoutHour=clampHour($event.target.value)"
                                               @input="editCheckoutHour=clampHour($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="editCheckoutHour=clampHour(editCheckoutHour-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                    <span class="bk-spin-sep">:</span>
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                                        <button type="button" class="bk-spin-btn" @click="editCheckoutMinute=clampMinute(editCheckoutMinute+1>=60?0:editCheckoutMinute+1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                        </button>
                                        <input type="number" min="0" max="59" class="bk-spin-input"
                                               :value="String(editCheckoutMinute).padStart(2,'0')"
                                               @change="editCheckoutMinute=clampMinute($event.target.value)"
                                               @input="editCheckoutMinute=clampMinute($event.target.value)"/>
                                        <button type="button" class="bk-spin-btn" @click="editCheckoutMinute=clampMinute(editCheckoutMinute-1<0?59:editCheckoutMinute-1)">
                                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {{-- ── Đã gán: KM / Phụ thu / Mã giảm giá ── --}}
            <div x-show="getSlot(editingDate)?.promotions?.length > 0 || getSlot(editingDate)?.surcharges?.length > 0 || getSlot(editingDate)?.coupons?.length > 0"
                 style="border-top:1px solid #f3f4f6;padding-top:14px;display:flex;flex-direction:column;gap:10px">

                <p style="font-size:10px;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;color:#9ca3af;margin:0">Đã gán — nhấn × để gỡ</p>

                {{-- Khuyến mãi --}}
                <div x-show="getSlot(editingDate)?.promotions?.length > 0">
                    <p style="font-size:10px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#f97316;margin-right:4px;vertical-align:middle"></span>
                        Khuyến mãi
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        <template x-for="promoId in (getSlot(editingDate)?.promotions ?? [])" :key="promoId">
                            <span style="display:inline-flex;align-items:center;gap:5px;background:#fffbeb;border:1px solid #fde68a;border-radius:20px;padding:3px 8px 3px 10px;font-size:11px;font-weight:600;color:#92400e;line-height:1.4">
                                <span x-text="promoOptions[promoId] ?? ('#'+promoId)"></span>
                                <button type="button"
                                        @click="removePromoItem(promoId)"
                                        :disabled="removingPromoId !== null"
                                        title="Gỡ khuyến mãi này"
                                        :style="removingPromoId===promoId ? 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#fbbf24;border:none;cursor:wait;flex-shrink:0;padding:0' : 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#fde68a;border:none;cursor:pointer;flex-shrink:0;padding:0;transition:background 0.12s'"
                                        onmouseover="if(!this.disabled)this.style.background='#fbbf24'" onmouseout="if(this.style.cursor!=='wait')this.style.background='#fde68a'">
                                    <svg x-show="removingPromoId !== promoId" style="width:8px;height:8px;color:#92400e" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <svg x-show="removingPromoId === promoId" class="animate-spin" style="width:9px;height:9px;color:#92400e" fill="none" viewBox="0 0 24 24">
                                        <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path style="opacity:.8" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                </button>
                            </span>
                        </template>
                    </div>
                </div>

                {{-- Phụ thu --}}
                <div x-show="getSlot(editingDate)?.surcharges?.length > 0">
                    <p style="font-size:10px;font-weight:700;color:#ea580c;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#eef122;margin-right:4px;vertical-align:middle"></span>
                        Phụ thu
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        <template x-for="surchargeId in (getSlot(editingDate)?.surcharges ?? [])" :key="surchargeId">
                            <span style="display:inline-flex;align-items:center;gap:5px;background:#fff7ed;border:1px solid #fed7aa;border-radius:20px;padding:3px 8px 3px 10px;font-size:11px;font-weight:600;color:#9a3412;line-height:1.4">
                                <span x-text="surchargeOptions[surchargeId] ?? ('#'+surchargeId)"></span>
                                <button type="button"
                                        @click="removePromoItem(surchargeId)"
                                        :disabled="removingPromoId !== null"
                                        title="Gỡ phụ thu này"
                                        :style="removingPromoId===surchargeId ? 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#fb923c;border:none;cursor:wait;flex-shrink:0;padding:0' : 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#fed7aa;border:none;cursor:pointer;flex-shrink:0;padding:0;transition:background 0.12s'"
                                        onmouseover="if(!this.disabled)this.style.background='#fb923c'" onmouseout="if(this.style.cursor!=='wait')this.style.background='#fed7aa'">
                                    <svg x-show="removingPromoId !== surchargeId" style="width:8px;height:8px;color:#9a3412" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <svg x-show="removingPromoId === surchargeId" class="animate-spin" style="width:9px;height:9px;color:#9a3412" fill="none" viewBox="0 0 24 24">
                                        <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path style="opacity:.8" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                </button>
                            </span>
                        </template>
                    </div>
                </div>

                {{-- Mã giảm giá --}}
                <div x-show="getSlot(editingDate)?.coupons?.length > 0">
                    <p style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-right:4px;vertical-align:middle"></span>
                        Mã giảm giá
                    </p>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        <template x-for="couponId in (getSlot(editingDate)?.coupons ?? [])" :key="couponId">
                            <span style="display:inline-flex;align-items:center;gap:5px;background:#fff5f5;border:1px solid #fecaca;border-radius:20px;padding:3px 8px 3px 10px;font-size:11px;font-weight:600;color:#991b1b;line-height:1.4">
                                <span x-text="couponOptions[couponId] ?? ('#'+couponId)"></span>
                                <button type="button"
                                        @click="removeCouponItem(couponId)"
                                        :disabled="removingCouponId !== null"
                                        title="Gỡ mã giảm giá này"
                                        :style="removingCouponId===couponId ? 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#f87171;border:none;cursor:wait;flex-shrink:0;padding:0' : 'display:flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;background:#fecaca;border:none;cursor:pointer;flex-shrink:0;padding:0;transition:background 0.12s'"
                                        onmouseover="if(!this.disabled)this.style.background='#f87171'" onmouseout="if(this.style.cursor!=='wait')this.style.background='#fecaca'">
                                    <svg x-show="removingCouponId !== couponId" style="width:8px;height:8px;color:#991b1b" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    <svg x-show="removingCouponId === couponId" class="animate-spin" style="width:9px;height:9px;color:#991b1b" fill="none" viewBox="0 0 24 24">
                                        <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path style="opacity:.8" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                </button>
                            </span>
                        </template>
                    </div>
                </div>

            </div>

        </div>{{-- end body --}}

        {{-- Footer --}}
        <div style="display:flex;gap:10px;padding:14px 20px;border-top:1px solid #f3f4f6;background:#fafafa;flex-shrink:0">
            <button type="button" x-show="getSlot(editingDate)" @click="deleteDate()"
                    :disabled="deletingDate"
                    style="flex:1;border:1.5px solid #fecaca;background:#fff5f5;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;color:#dc2626;cursor:pointer;transition:background 0.15s;display:flex;align-items:center;justify-content:center;gap:6px"
                    onmouseover="if(!this.disabled)this.style.background='#fee2e2'" onmouseout="this.style.background='#fff5f5'">
                <svg x-show="deletingDate" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span x-text="deletingDate ? 'Đang xóa...' : 'Xóa ngày này'"></span>
            </button>
            <button type="button" @click="saveDate()" :disabled="!editPrice || savingDate"
                    class="bk-apply-btn"
                    :style="(!editPrice||savingDate)?'background:#4e6b4c;opacity:.4;cursor:not-allowed':'background:#4e6b4c'">
                <svg x-show="savingDate" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span x-text="savingDate ? 'Đang lưu...' : 'Lưu ngày này'"></span>
            </button>
        </div>

    </div>
</div>
