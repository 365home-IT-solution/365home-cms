<div x-data="{
    selectedSlots: [],
    selectedRoomId: null,
    selectedRoomIsActive: null,
    fullBookingDiscount: 0,
    hasFullDayBooking: false,
    fullDayDates: [], // Thêm để track ngày nào full booking

    resetSelection() {
        this.selectedSlots = [];
        this.selectedRoomId = null;
        this.selectedRoomIsActive = null;
        this.fullBookingDiscount = 0;
        this.hasFullDayBooking = false;
        this.fullDayDates = [];
    },

    toggleSlot(el, slot) {
        if (this.selectedRoomId === null) {
            this.selectedRoomId = slot.roomId;
            this.selectedRoomIsActive = slot.is_activated;
        }

        if (this.selectedRoomId !== slot.roomId) {
            return;
        }

        // Use selectedSlots as source of truth (not el.classList) to avoid DOM lag on quick clicks
        const isSelected = this.selectedSlots.some(
            s => s.timeslotId === slot.timeslotId && s.date === slot.date
        );

        if (isSelected) {
            el.classList.remove('active');
            // Fix: filter by BOTH date AND timeslotId — same timeslot can exist on multiple dates
            this.selectedSlots = this.selectedSlots.filter(
                s => !(s.timeslotId === slot.timeslotId && s.date === slot.date)
            );

            if (this.selectedSlots.length === 0) {
                this.selectedRoomId = null;
                this.selectedRoomIsActive = null;
                this.fullBookingDiscount = 0;
                this.hasFullDayBooking = false;
                this.fullDayDates = [];
            }
        } else {
            el.classList.add('active');
            this.selectedSlots.push(slot);
        }

        this.checkFullBooking();
    },

    checkFullBooking() {
        if (!this.selectedRoomId || this.selectedSlots.length === 0) {
            this.fullBookingDiscount = 0;
            this.hasFullDayBooking = false;
            this.fullDayDates = [];
            return;
        }

        const slotsByDate = {};
        this.selectedSlots.forEach(slot => {
            if (!slotsByDate[slot.date]) {
                slotsByDate[slot.date] = [];
            }
            slotsByDate[slot.date].push(slot);
        });

        const firstSlot = this.selectedSlots[0];
        const totalSlotsInRoom = firstSlot.totalSlotsInRoom;
        const fullBookingDiscountValue = firstSlot.fullBookingDiscountValue;

        let hasFullDay = false;
        this.fullDayDates = [];

        Object.entries(slotsByDate).forEach(([date, slots]) => {
            if (slots.length === totalSlotsInRoom) {
                hasFullDay = true;
                // Chỉ track fullDayDates nếu có cấu hình full_booking_discount
                if (fullBookingDiscountValue) {
                    this.fullDayDates.push(date);
                }
            }
        });

        // Chỉ kích hoạt full booking mode khi có cấu hình giảm giá
        this.hasFullDayBooking = hasFullDay && !!fullBookingDiscountValue;

        if (this.hasFullDayBooking) {
            this.fullBookingDiscount = this.calculateDiscountValue(
                this.totalPrice,
                fullBookingDiscountValue
            );
        } else {
            this.fullBookingDiscount = 0;
        }
    },

    calculateDiscountValue(totalPrice, discountStr) {
        if (!discountStr) return 0;

        if (discountStr.includes('%')) {
            const percentage = parseFloat(discountStr.replace('%', ''));
            return totalPrice * (percentage / 100);
        } else {
            return parseFloat(discountStr.replace(/[.,]/g, ''));
        }
    },

    get totalOriginalPrice() {
        return this.selectedSlots.reduce((sum, s) => sum + (s.basePrice || s.originalPrice || s.price), 0);
    },

    // SỬA: Không tính promo discount cho ngày full booking
    get totalPromoDiscount() {
        return this.selectedSlots.reduce((sum, s) => {
            // Nếu ngày này full booking thì không tính promo discount
            if (this.fullDayDates.includes(s.date)) {
                return sum;
            }
            return sum + (s.promoDiscount || 0);
        }, 0);
    },

    get totalIncreaseAmount() {
        return this.selectedSlots.reduce((sum, s) => {
            return sum + (s.increaseAmount || 0);
        }, 0);
    },

    get totalPrice() {
        return this.selectedSlots.reduce((sum, s) => {
            // Nếu là ngày full booking, tính lại giá = basePrice + increaseAmount
            if (this.fullDayDates.includes(s.date)) {
                return sum + (s.basePrice + s.increaseAmount);
            }
            return sum + s.price;
        }, 0);
    },

    get discountRate() {
        if (this.hasFullDayBooking || this.selectedSlots.length === 0) {
            return 0;
        }

        const rules = this.selectedSlots[0].bulkDiscountRules;
        if (!rules || rules.length === 0) {
            return 0;
        }

        const count = this.selectedSlots.length;
        // Lấy rule có slots <= count, ưu tiên rule cao nhất
        const matched = rules
            .filter(r => count >= r.slots)
            .sort((a, b) => b.slots - a.slots)[0];

        return matched ? matched.discount / 100 : 0;
    },

    get discount() {
        return this.totalPrice * this.discountRate;
    },

    get totalAfterDiscount() {
        return this.totalPrice - this.discount;
    },

    get totalAfterAllDiscounts() {
        return this.totalAfterDiscount - this.fullBookingDiscount;
    },

    // SỬA: Không hiển thị discount promotions cho ngày full booking
    get promotionSummary() {
        const summary = {
            increases: {},
            discounts: {}
        };

        this.selectedSlots.forEach(slot => {
            // Xử lý TĂNG GIÁ (luôn hiển thị)
            if (slot.increasePromotions && slot.increasePromotions.length > 0) {
                slot.increasePromotions.forEach(promo => {
                    if (!summary.increases[promo.name]) {
                        summary.increases[promo.name] = {
                            type: promo.type,
                            total: 0,
                            count: 0
                        };
                    }
                    summary.increases[promo.name].total += promo.amount || 0;
                    summary.increases[promo.name].count++;
                });
            }

            // Xử lý GIẢM GIÁ (chỉ hiển thị nếu KHÔNG phải ngày full booking)
            if (!this.fullDayDates.includes(slot.date)) {
                if (slot.discountPromotions && slot.discountPromotions.length > 0) {
                    slot.discountPromotions.forEach(promo => {
                        if (!summary.discounts[promo.name]) {
                            summary.discounts[promo.name] = {
                                type: promo.type,
                                total: 0,
                                count: 0
                            };
                        }
                        summary.discounts[promo.name].total += promo.amount || 0;
                        summary.discounts[promo.name].count++;
                    });
                }
            }
        });

        return summary;
    }
}" x-on:book-category-changed.window="resetSelection()">
    <div class="w-full mx-auto">
        @include('bladethemev1::livewire.book._header')

        <div id="default-styled-tab-content" wire:loading.class="opacity-50 pointer-events-none" wire:target="setActiveCategoryTab">
            @if(!empty($activeCategoryData))
            @php $category = $activeCategoryData; @endphp
            <div class="pb-10 relative" id="styled-{{ \Str::slug($category['name']) }}" role="tabpanel"
                aria-labelledby="styled-{{ \Str::slug($category['name']) }}-tab" wire:key="book-category-{{ $category['id'] }}">

                @php
                // Dùng chung cho _mobile.blade.php trên mọi kích thước màn hình (đã bỏ bảng
                // riêng cho desktop). Hiển thị mặc định 10 ngày, người dùng bấm "Xem thêm 5 ngày"
                // để hiện dần các ngày còn lại (xử lý bằng Alpine x-show theo $loop->index, không
                // cần gọi lại server).
                $dates = $this->getDatesForOneMonth();
                $styleOneRooms = collect($category['products'])->filter(fn($r) => ($r->styles ?? 1) == 1)->values();
                $totalStyleOneRooms = $styleOneRooms->count();
                $today = now()->startOfDay();
                // Gán mỗi phòng 1 màu riêng (xoay vòng theo bảng màu) để phần header/khung giờ
                // đổi màu theo phòng đang chọn (room-color) — trước đây $productColors chưa từng
                // được định nghĩa nên mọi phòng lặng lẽ dùng chung 1 màu mặc định.
                $roomColorPalette = ['#4e6b4c', '#2563eb', '#dc2626', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#0f766e'];
                $productColors = [];
                foreach ($styleOneRooms as $roomIdx => $roomForColor) {
                    $productColors[$roomForColor->id] = ['color' => $roomColorPalette[$roomIdx % count($roomColorPalette)]];
                }
                // Auto-compute contrast text color from hex background
                $autoTextColor = function(string $hex): string {
                $hex = ltrim($hex, '#');
                if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                }
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 128 ? '#111827' : '#ffffff';
                };
                // Xây dựng bản đồ màu theo thứ tự xuất hiện của order_id trong category
                // Đảm bảo mỗi đơn có màu riêng, không trùng giữa các phòng khác nhau
               $orderColorMap = [];
                @endphp

                @include('bladethemev1::livewire.book._legend')

                {{-- Desktop (lg+): bảng đặt phòng và bảng tính giá nằm 2 cột, dùng hết chiều
                     ngang; mobile/tablet giữ nguyên xếp dọc như cũ. (Dùng CSS thuần trong
                     _styles.blade.php thay vì class Tailwind để không phụ thuộc build lại.) --}}
                <div class="book-desktop-layout">
                    @include('bladethemev1::livewire.book._mobile')

                    @include('bladethemev1::livewire.book._pricing')
                </div>

            </div>
            @endif
        </div>

        {{-- Mobile sticky pricing bar (shown after selecting a time slot) --}}
        <div class="book-mobile-price-bar"
             x-show="selectedSlots.length > 0"
             x-transition:enter="bar-enter"
             x-transition:enter-start="bar-enter-from"
             x-transition:enter-end="bar-enter-to"
             x-transition:leave="bar-leave"
             x-transition:leave-start="bar-leave-from"
             x-transition:leave-end="bar-leave-to"
             style="display:none">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p style="font-size:0.7rem;color:#6b7280;font-weight:500;margin:0 0 2px;">Tổng tiền tạm tính</p>
                    <p style="font-size:1.1rem;font-weight:800;color:#4e6b4c;margin:0;line-height:1.2;" x-text="totalAfterAllDiscounts.toLocaleString() + ' đ'"></p>
                    <p style="font-size:0.65rem;color:#9ca3af;margin:0;" x-text="selectedSlots.length + ' khung giờ đã chọn'"></p>
                </div>
                <div x-show="selectedRoomIsActive === true || selectedRoomId === null" style="flex-shrink:0">
                    <button @click="$wire.saveAndRedirect(selectedSlots)"
                            :disabled="selectedSlots.length === 0"
                            style="padding:10px 22px;border-radius:999px;font-weight:800;font-size:0.85rem;color:white;background:linear-gradient(135deg,#4e6b4c,#6a8f68,#5a7d58);border:none;cursor:pointer;box-shadow:0 6px 18px rgba(78,107,76,0.35);white-space:nowrap;">
                        Đặt phòng ngay
                    </button>
                </div>
            </div>
        </div>

        @include('bladethemev1::livewire.book._styles')
    </div>
</div>