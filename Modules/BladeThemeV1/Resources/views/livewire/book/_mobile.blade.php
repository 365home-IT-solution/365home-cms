{{--
    Mobile two-panel booking grid.
    Included from book.blade.php — inherits:
      $dates, $styleOneRooms, $totalStyleOneRooms, $today, $category
--}}
<div x-data="{
    activeRoomIdx: 0, totalRooms: {{ $totalStyleOneRooms }},
    slotPage: 0, slotsPerPage: 5,
    slotCounts: [{{ $styleOneRooms->map(fn($r) => $r->roomTimeSlots->count())->join(', ') }}],
    categorySlug: '{{ \Str::slug($category['name']) }}',
    touchStartX: 0,
    dateLimit: 10, totalDates: {{ count($dates) }},
    get remainingDates() { return Math.max(0, Math.min(5, this.totalDates - this.dateLimit)); },
    loadMoreDates() { this.dateLimit = Math.min(this.dateLimit + 5, this.totalDates); },
    slideDir: 1,
    get totalSlotPages() { return Math.ceil((this.slotCounts[this.activeRoomIdx] ?? 5) / this.slotsPerPage); },
    changeRoom(dir) {
        const newIdx = this.activeRoomIdx + dir;
        if (newIdx >= 0 && newIdx < this.totalRooms) {
            this.slideDir = dir;
            this.activeRoomIdx = newIdx;
            this.slotPage = 0;
            return;
        }
        // Cross-branch: navigate to neighbouring tab when at the boundary
        const tabs = [...document.querySelectorAll('#default-styled-tab button')];
        const currentTabIdx = tabs.findIndex(t => t.id === 'styled-' + this.categorySlug + '-tab');
        const nextTabIdx = currentTabIdx + (dir > 0 ? 1 : -1);
        if (nextTabIdx >= 0 && nextTabIdx < tabs.length) {
            tabs[nextTabIdx].click();
            window.dispatchEvent(new CustomEvent('book-activate-room', {
                detail: { tabId: tabs[nextTabIdx].id, fromEnd: dir < 0 }
            }));
        }
    },
    onTouchStart(e) { this.touchStartX = e.touches[0].clientX; },
    onTouchEnd(e) {
        const diff = this.touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) { this.changeRoom(diff > 0 ? 1 : -1); }
    }
}"
    @touchstart.passive="onTouchStart($event)"
    @touchend.passive="onTouchEnd($event)"
    @book-activate-room.window="
        if ($event.detail.tabId === 'styled-' + categorySlug + '-tab') {
            activeRoomIdx = $event.detail.fromEnd ? totalRooms - 1 : 0;
            slotPage = 0;
        }
    "
>

    <div class="book-card-outer">

    {{-- ── Room carousel header — trải rộng hết chiều ngang (w-full), không còn chừa trống
         phía trên cột "Ngày" như trước ── --}}
    <div class="book-top-row">
        <div class="book-room-nav-wrap w-full">
            <button class="book-nav-btn" type="button"
                @click="changeRoom(-1)"
                aria-label="Phòng trước">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <div class="book-room-titles-wrap">
                @foreach ($styleOneRooms as $ri => $roomTitle)
                <div x-show="activeRoomIdx === {{ $ri }}"
                    class="book-room-title-block">
                    <h3 class="book-room-name">{{ $roomTitle->name }}</h3>
                </div>
                @endforeach
            </div>
            <button class="book-nav-btn" type="button"
                @click="changeRoom(1)"
                aria-label="Phòng tiếp">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- ── Slot page navigation strip (only when room has > 5 slots) ── --}}
    @foreach ($styleOneRooms as $ri => $room)
    @if($room->roomTimeSlots->count() > 5)
    <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="slot-page-strip">
        <button class="slot-pg-btn slot-pg-prev" @click="slotPage = Math.max(0, slotPage - 1)" :disabled="slotPage === 0">
            &#8249; <span>Quay lại</span>
        </button>
        <span class="slot-pg-info" x-text="'Khung giờ ' + (slotPage * slotsPerPage + 1) + '–' + Math.min((slotPage + 1) * slotsPerPage, slotCounts[activeRoomIdx])"></span>
        <button class="slot-pg-btn slot-pg-next" @click="slotPage = Math.min(totalSlotPages - 1, slotPage + 1)" :disabled="slotPage >= totalSlotPages - 1">
            <span>Xem thêm</span> &#8250;
        </button>
    </div>
    @endif
    @endforeach

    {{-- ── Fixed column headers (ngoài khung cuộn — không còn cần position:sticky vì cuộn
         giờ nằm bên trong từng card thân) ── --}}
    <div class="book-grid-header">
        <div class="book-col-header">Ngày</div>
        <div class="book-slots-headers-wrap">
            @foreach ($styleOneRooms as $ri => $room)
            <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="book-slots-header-row"
                :class="slideDir === 1 ? 'book-slide-in-right' : 'book-slide-in-left'">
                @foreach ($room->roomTimeSlots as $roomTimeSlot)
                @php
                $startTime   = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time);
                $endTime     = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                $isOvernight = $endTime->isNextDay() || $endTime->lt($startTime);
                @endphp
                <div class="book-slot-th" x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                    <span class="book-slot-time-start">{{ $startTime->format('H:i') }}</span><span class="book-slot-time-sep">&nbsp;–&nbsp;</span><br class="book-slot-time-br"><span class="book-slot-time-end">{{ $endTime->format('H:i') }}</span>
                    @if($isOvernight)<span class="book-overnight-tag text-xs">Qua đêm</span>@endif
                </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── Thân: khung (viền/bo góc) của mỗi card đứng yên cố định, chỉ nội dung bên trong
         cuộn dọc, đồng bộ 2 chiều qua @scroll để cột Ngày và khung giờ luôn khớp hàng ── --}}
    <div class="book-grid-outer">

        {{-- Left: Dates card --}}
        <div class="book-dates-card">
            <div class="book-dates-scroll" x-ref="bookDatesScroll"
                @scroll="$refs['bookSlotsScroll' + activeRoomIdx].scrollTop = $event.target.scrollTop">
                @foreach ($dates as $date)
                @php $dateShort = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->format('d/m'); @endphp
                <div class="book-date-row{{ $date['is_today'] ? ' is-today' : '' }}" x-show="{{ $loop->index }} < dateLimit" x-cloak>
                    <span class="book-date-day">{{ $date['day'] }}</span>
                    <span class="book-date-num">{{ $dateShort }}</span>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Right: Scrollable slots per room --}}
        <div class="book-slots-outer">
            @foreach ($styleOneRooms as $ri => $room)
            <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="book-slots-card"
                :class="slideDir === 1 ? 'book-slide-in-right' : 'book-slide-in-left'">
                <div class="book-slots-scroll" x-ref="bookSlotsScroll{{ $ri }}"
                    @scroll="$refs.bookDatesScroll.scrollTop = $event.target.scrollTop">

                {{-- One row per date --}}
                @foreach ($dates as $date)
                <div class="book-slots-row" x-show="{{ $loop->index }} < dateLimit" x-cloak>
                    @foreach ($room->roomTimeSlots as $roomTimeSlot)
                    @php
                    $price    = $roomTimeSlot->price ?? 0;
                    $classes  = '';
                    $isSelectable = true;
                    $finalPrice   = $price;

                    $currentDateTime = \Carbon\Carbon::createFromFormat(
                        'd-m-Y H:i:s',
                        $date['date'] . ' ' . $roomTimeSlot->timeSlot->start_time,
                    );

                    $status      = 'available';
                    $matchedItem = null;

                    foreach ($room->orderItems as $orderItem) {
                        $checkin  = \Carbon\Carbon::parse($orderItem->checkin_date);
                        $checkout = \Carbon\Carbon::parse($orderItem->checkout_date);
                        if ($currentDateTime->between($checkin, $checkout)) {
                            if ($orderItem->order) { $status = $orderItem->order->status; }
                            $matchedItem = $orderItem;
                            break;
                        }
                    }

                    if ($status === 'pending') {
                        $classes .= ' pending'; $isSelectable = false;
                    } elseif (in_array($status, ['paid', 'shipped', 'confirmed'])) {
                        $classes .= ' booked'; $isSelectable = false;
                    }
                    
                    $orderColor = null;
                   if ($matchedItem) {
                        if (in_array($status, ['paid', 'shipped', 'confirmed'])) {
                            $orderColor = '#4e6b4c';
                        } elseif ($status === 'deposit') {
                            $orderColor = '#3b82f6';
                        } elseif ($status === 'pending') {
                            $orderColor = '#f97316';
                        } else {
                            $orderColor = '#94a3b8';
                        }
                    }

                    $slotDate   = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->startOfDay();
                    $yesterday  = now()->subDay()->startOfDay();
                    $cutoffTime = now()->startOfDay()->setTime(7, 30, 0);

                    if ($slotDate->lt($yesterday)) {
                        $isSelectable = false; $classes .= ' past-date';
                    } elseif ($slotDate->eq($yesterday)) {
                        if (now()->gte($cutoffTime)) { $isSelectable = false; $classes .= ' past-date'; }
                    } elseif ($slotDate->eq($today)) {
                        $slotEndTimeParsed = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                        $isOvernightSlot   = $slotEndTimeParsed->lt(\Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time));
                        $slotEndDateTime   = $slotDate->copy()->setTime(
                            $slotEndTimeParsed->hour,
                            $slotEndTimeParsed->minute,
                            $slotEndTimeParsed->second
                        );
                        if ($isOvernightSlot) { $slotEndDateTime->addDay(); }
                        if (now()->gte($slotEndDateTime)) { $isSelectable = false; $classes .= ' past-date'; }
                    }

                    $rtsSettings  = is_array($roomTimeSlot->settings)
                        ? $roomTimeSlot->settings
                        : (json_decode($roomTimeSlot->settings, true) ?? []);
                    $blockedDates = $rtsSettings['blocked_dates'] ?? [];
                    $slotDateYmd  = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->toDateString();
                    if (in_array($slotDateYmd, $blockedDates)) { $isSelectable = false; $classes .= ' blocked'; }

                    $slotStartTime = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time)->format('H:i:s');
                    $priceData     = $this->calculateSlotPrice($roomTimeSlot, $date['date'], $slotStartTime);
                    $finalPrice    = $priceData['final_price'];
                    $originalPrice = $priceData['original_price'];
                    $totalDiscount = $priceData['total_discount'];
                    $hasPromotion  = $priceData['has_promotion'];
                    $isIncrease    = $priceData['is_increase'];
                    $activePromotions = $priceData['promotions'] ?? [];

                    $hasDiscountPromotion = false; $hasIncreasePromotion = false;
                    $discountPromotions   = []; $increasePromotions = [];

                    foreach ($activePromotions as $promo) {
                        if (in_array($promo->type, ['percentage', 'fixed'])) { $hasDiscountPromotion = true; $discountPromotions[] = $promo; }
                        if (in_array($promo->type, ['increase_percentage', 'increase_fixed'])) { $hasIncreasePromotion = true; $increasePromotions[] = $promo; }
                    }

                    $showPromotion = $hasDiscountPromotion;
                    if ($hasDiscountPromotion) { $classes .= ' promo'; }
                    if ($hasIncreasePromotion && !$hasDiscountPromotion) { $classes .= ' promo-increase'; }

                    $discountPromotionsData = collect($discountPromotions)->map(function($p) use ($originalPrice, $priceData) {
                        $amount = 0;
                        if ($p->type === 'percentage') { $amount = ($originalPrice + $priceData['increase_amount']) * ($p->value / 100); }
                        elseif ($p->type === 'fixed') { $amount = $p->value; }
                        return ['name' => $p->name, 'type' => $p->type, 'value' => $p->value, 'amount' => $amount, 'lable_client' => $p->lable_client ?? null, 'image' => $p->image ?? null];
                    })->toArray();

                    $increasePromotionsData = collect($increasePromotions)->map(function($p) use ($originalPrice) {
                        $amount = 0;
                        if ($p->type === 'increase_percentage') { $amount = $originalPrice * ($p->value / 100); }
                        elseif ($p->type === 'increase_fixed') { $amount = $p->value; }
                        return ['name' => $p->name, 'type' => $p->type, 'value' => $p->value, 'amount' => $amount, 'lable_client' => $p->lable_client ?? null, 'image' => $p->image ?? null];
                    })->toArray();

                    $displayPromotion         = $increasePromotions[0] ?? null;
                    $displayDiscountPromotion = $discountPromotionsData[0] ?? null;
                    @endphp

                    <div class="book-slot-cell" x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                        <div class="selectable {{ $classes }}"
                           style="{{ !$isSelectable ? 'pointer-events:none;opacity:0.55;' : 'cursor:pointer;' }}{{ $orderColor ? '--order-color:' . $orderColor . ';' : '' }}"
                            @click="toggleSlot($el, {
                                date: '{{ $date['date'] }}',
                                startTime: '{{ $roomTimeSlot->timeSlot->start_time }}',
                                endTime: '{{ $roomTimeSlot->timeSlot->end_time }}',
                                timeslotId: '{{ $roomTimeSlot->timeSlot->id }}',
                                roomId: '{{ $room->id }}',
                                price: {{ $finalPrice }},
                                originalPrice: {{ $priceData['price_after_increase'] }},
                                basePrice: {{ $originalPrice }},
                                increaseAmount: {{ $priceData['increase_amount'] ?? 0 }},
                                promoDiscount: {{ $totalDiscount }},
                                hasDiscount: {{ $hasDiscountPromotion ? 'true' : 'false' }},
                                hasIncrease: {{ $hasIncreasePromotion ? 'true' : 'false' }},
                                isIncrease: {{ $isIncrease ? 'true' : 'false' }},
                                is_activated: {{ $room->is_activated ? 'true' : 'false' }},
                                overNight: {{ $roomTimeSlot->over_night ?? 0 }},
                                totalSlotsInRoom: {{ $room->roomTimeSlots->count() }},
                                fullBookingDiscountValue: '{{ $room->full_booking_discount }}',
                                bulkDiscountRules: {{ json_encode($room->bulk_discount_rules ?? []) }},
                                discountPromotions: {{ json_encode($discountPromotionsData) }},
                                increasePromotions: {{ json_encode($increasePromotionsData) }}
                            })">
                            @if (str_contains($classes, 'blocked') || str_contains($classes, 'booked'))
                            <svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="11" width="16" height="9" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                            </svg>
                            @endif
                            @if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->image)
                            <div class="promotion-corner-image">
                                <img src="{{ asset('storage/' . $displayPromotion->image) }}" alt="{{ $displayPromotion->name }}" class="corner-img">
                            </div>
                            @endif
                            @if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->lable_client)
                            <div class="promotion-center-label">{!! $displayPromotion->lable_client !!}</div>
                            @endif
                            @if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['image']))
                            <div class="promotion-corner-image">
                                <img src="{{ asset('storage/' . $displayDiscountPromotion['image']) }}" alt="{{ $displayDiscountPromotion['name'] }}" class="corner-img">
                            </div>
                            @endif
                            @if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['lable_client']))
                            <div class="promotion-center-label">{!! $displayDiscountPromotion['lable_client'] !!}</div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @endforeach

                </div>{{-- end .book-slots-scroll --}}
            </div>
            @endforeach
        </div>{{-- end .book-slots-outer --}}
    </div>{{-- end .book-grid-outer --}}

    {{-- ── Xem thêm ngày (mỗi lần bấm hiện thêm tối đa 5 ngày kế tiếp, tự ẩn khi đã hiện hết) ── --}}
    <div class="book-loadmore-row" x-show="dateLimit < totalDates" x-cloak>
        <button type="button" class="book-loadmore-btn" @click="loadMoreDates()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
            <span x-text="'Xem thêm ' + remainingDates + ' ngày'"></span>
        </button>
    </div>

    </div>{{-- end .book-card-outer --}}
</div>{{-- end x-data room carousel --}}
