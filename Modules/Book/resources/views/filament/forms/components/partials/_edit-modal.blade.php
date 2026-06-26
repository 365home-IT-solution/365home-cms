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

            {{-- ── Khuyến mãi / Phụ thu / Mã giảm giá ── --}}
            <div style="border-top:1px solid #f3f4f6;padding-top:14px;display:flex;flex-direction:column;gap:12px">

                {{-- Khuyến mãi --}}
                @if(!empty($promotionDiscountOptions))
                <div>
                    <p style="font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#b45309;margin-bottom:6px">
                        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f97316;margin-right:4px;vertical-align:middle"></span>
                        Khuyến mãi
                    </p>
                    <div style="display:flex;flex-direction:column;gap:4px;max-height:100px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:8px;padding:4px">
                        @foreach($promotionDiscountOptions as $pid => $pname)
                        <div style="display:flex;align-items:center;gap:7px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:background 0.1s"
                             :style="editPromotions.includes({{ (int)$pid }}) ? 'background:#fffbeb' : ''"
                             @click="toggleEditPromo({{ (int)$pid }})">
                            <div style="width:14px;height:14px;border-radius:3px;border:1.5px solid;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all 0.1s"
                                 :style="editPromotions.includes({{ (int)$pid }}) ? 'background:#f97316;border-color:#f97316' : 'border-color:#d1d5db;background:#fff'">
                                <svg x-show="editPromotions.includes({{ (int)$pid }})" style="width:9px;height:9px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span style="font-size:11px;font-weight:500"
                                  :style="editPromotions.includes({{ (int)$pid }}) ? 'color:#92400e' : 'color:#374151'">{{ $pname }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Phụ thu --}}
                @if(!empty($promotionSurchargeOptions))
                <div>
                    <p style="font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#ea580c;margin-bottom:6px">
                        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#eef122;margin-right:4px;vertical-align:middle"></span>
                        Phụ thu
                    </p>
                    <div style="display:flex;flex-direction:column;gap:4px;max-height:100px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:8px;padding:4px">
                        @foreach($promotionSurchargeOptions as $sid => $sname)
                        <div style="display:flex;align-items:center;gap:7px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:background 0.1s"
                             :style="editSurcharges.includes({{ (int)$sid }}) ? 'background:#fff7ed' : ''"
                             @click="toggleEditSurcharge({{ (int)$sid }})">
                            <div style="width:14px;height:14px;border-radius:3px;border:1.5px solid;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all 0.1s"
                                 :style="editSurcharges.includes({{ (int)$sid }}) ? 'background:#ea580c;border-color:#ea580c' : 'border-color:#d1d5db;background:#fff'">
                                <svg x-show="editSurcharges.includes({{ (int)$sid }})" style="width:9px;height:9px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span style="font-size:11px;font-weight:500"
                                  :style="editSurcharges.includes({{ (int)$sid }}) ? 'color:#9a3412' : 'color:#374151'">{{ $sname }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Mã giảm giá --}}
                @if(!empty($couponOptions))
                <div>
                    <p style="font-size:10px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#dc2626;margin-bottom:6px">
                        <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;margin-right:4px;vertical-align:middle"></span>
                        Mã giảm giá
                    </p>
                    <div style="display:flex;flex-direction:column;gap:4px;max-height:100px;overflow-y:auto;border:1px solid #f3f4f6;border-radius:8px;padding:4px">
                        @foreach($couponOptions as $cid => $cname)
                        <div style="display:flex;align-items:center;gap:7px;padding:4px 8px;border-radius:6px;cursor:pointer;transition:background 0.1s"
                             :style="editCoupons.includes({{ (int)$cid }}) ? 'background:#fff5f5' : ''"
                             @click="toggleEditCoupon({{ (int)$cid }})">
                            <div style="width:14px;height:14px;border-radius:3px;border:1.5px solid;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all 0.1s"
                                 :style="editCoupons.includes({{ (int)$cid }}) ? 'background:#ef4444;border-color:#ef4444' : 'border-color:#d1d5db;background:#fff'">
                                <svg x-show="editCoupons.includes({{ (int)$cid }})" style="width:9px;height:9px;color:#fff" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <span style="font-size:11px;font-weight:500"
                                  :style="editCoupons.includes({{ (int)$cid }}) ? 'color:#991b1b' : 'color:#374151'">{{ $cname }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

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
            <button type="button" @click="saveDate()" :disabled="savingDate"
                    class="bk-apply-btn"
                    :style="savingDate?'background:#4e6b4c;opacity:.4;cursor:not-allowed':'background:#4e6b4c'">
                <svg x-show="savingDate" class="animate-spin" style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span x-text="savingDate ? 'Đang lưu...' : 'Lưu ngày này'"></span>
            </button>
        </div>

    </div>
</div>
