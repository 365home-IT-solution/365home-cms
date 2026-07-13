{{--
    1 ô "khung giờ x ngày" trong bảng đặt lịch — tách riêng để dùng chung cho cả bản mobile
    (carousel từng phòng, book/_mobile.blade.php) và bản desktop (hiện tất cả phòng cùng lúc,
    book/_desktop-grid.blade.php), tránh lặp lại ~150 dòng logic tính trạng thái/giá ở 2 nơi.
    Nhận vào qua @include: $room, $date, $roomTimeSlot. $today lấy từ scope cha (book.blade.php).
--}}
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
<div class="selectable {{ $classes }}"
    style="{{ !$isSelectable ? 'pointer-events:none;opacity:0.55;' : 'cursor:pointer;' }}{{ $orderColor ? '--order-color:' . $orderColor . ';' : '' }}"
    data-room-id="{{ $room->id }}" data-timeslot-id="{{ $roomTimeSlot->timeSlot->id }}" data-date="{{ $date['date'] }}"
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
