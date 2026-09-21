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
    } elseif (in_array($status, ['paid', 'confirmed'])) {
        $classes .= ' booked'; $isSelectable = false;
    }

    $orderColor = null;
    if ($matchedItem) {
        if (in_array($status, ['paid', 'confirmed'])) {
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

    // Đang bị ADMIN giữ chỗ real-time (xem TimeslotHoldService) — hiển thị MỜ, không ẩn hoàn toàn.
    // Tra map đã nạp sẵn 1 lần/lượt render (Book::getActiveHoldsMap()) thay vì tự query riêng cho
    // từng ô — xem lý do ở TimeslotHoldService::getActiveHoldsMap().
    $heldByName = null;
    if ($isSelectable) {
        $activeHold = $this->getActiveHoldsMap()[$roomTimeSlot->id . '|' . $slotDateYmd] ?? null;
        if ($activeHold) {
            $isSelectable = false;
            $classes .= ' held';
            $heldByName = $activeHold->user->fullname ?? $activeHold->user->email ?? 'nhân viên';
        }
    }

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

    // is_activated/totalSlotsInRoom/fullBookingDiscountValue/bulkDiscountRules KHÔNG đổi theo
    // từng ô (chỉ theo phòng) — nhúng lại y hệt cho MỖI ô (hàng trăm-nghìn ô/trang chi nhánh
    // nhiều phòng) là lãng phí HTML thuần, đã bị Google Search Console báo "kích thước HTML quá
    // lớn" (>2MB) ở chi nhánh 8 phòng. Đã chuyển 4 field này ra data-room-meta, render 1
    // lần/phòng (book/_desktop-grid.blade.php, book/_mobile.blade.php) — toggleSlot() ở
    // book.blade.php tự đọc lại + merge vào $slot lúc click, giữ nguyên object cuối cùng như cũ.

    // Phần còn lại của $slot (giá, khuyến mãi, giờ bắt đầu/kết thúc...) cũng KHÔNG nhúng vào @click
    // của từng ô nữa: mobile (book/_mobile.blade.php) + desktop (book/_desktop-grid.blade.php) cùng
    // render 1 ô 2 lần, mỗi lần ~700 ký tự JSON — chiếm >50% HTML trang chủ (tỷ lệ text/HTML thấp
    // bị Semrush cảnh báo). Gom vào $slotMap (ArrayObject khai báo ở book.blade.php), render 1 lần
    // duy nhất thành <script type="application/json" data-slot-map>; toggleSlot() tra lại theo
    // roomId|timeslotId|date lấy từ data-* của ô. Ép kiểu số (+ 0) để khớp với literal số mà @click
    // cũ in ra (giá từ DB có thể là chuỗi decimal, json_encode sẽ ra chuỗi thay vì số).
    $slotMap[$room->id . '|' . $roomTimeSlot->timeSlot->id . '|' . $date['date']] = [
        'startTime'          => (string) $roomTimeSlot->timeSlot->start_time,
        'endTime'            => (string) $roomTimeSlot->timeSlot->end_time,
        'price'              => $finalPrice + 0,
        'originalPrice'      => $priceData['price_after_increase'] + 0,
        'basePrice'          => $originalPrice + 0,
        'increaseAmount'     => ($priceData['increase_amount'] ?? 0) + 0,
        'promoDiscount'      => $totalDiscount + 0,
        'hasDiscount'        => (bool) $hasDiscountPromotion,
        'hasIncrease'        => (bool) $hasIncreasePromotion,
        'isIncrease'         => (bool) $isIncrease,
        'overNight'          => (int) ($roomTimeSlot->over_night ?? 0),
        'discountPromotions' => $discountPromotionsData,
        'increasePromotions' => $increasePromotionsData,
    ];

    // Ảnh/nhãn khuyến mãi phủ lên ô — dựng sẵn thành 1 chuỗi thay vì 5 khối @if: mỗi @if trong
    // component Livewire bị chèn thêm 1 cặp comment <!--[if BLOCK]--> vào HTML (5 cặp/ô x hàng trăm
    // ô = phần đáng kể của HTML trang chủ). Điều kiện và markup giữ NGUYÊN như bản @if cũ.
    $overlayHtml = '';
    if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->image) {
        $overlayHtml .= '<div class="promotion-corner-image"><img src="' . e(asset('storage/' . $displayPromotion->image))
            . '" alt="' . e($displayPromotion->name) . '" class="corner-img"></div>';
    }
    if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->lable_client) {
        $overlayHtml .= '<div class="promotion-center-label">' . $displayPromotion->lable_client . '</div>';
    }
    if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['image'])) {
        $overlayHtml .= '<div class="promotion-corner-image"><img src="' . e(asset('storage/' . $displayDiscountPromotion['image']))
            . '" alt="' . e($displayDiscountPromotion['name']) . '" class="corner-img"></div>';
    }
    if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['lable_client'])) {
        $overlayHtml .= '<div class="promotion-center-label">' . $displayDiscountPromotion['lable_client'] . '</div>';
    }
    if (str_contains($classes, 'held')) {
        $overlayHtml .= '<div class="lock-icon" title="' . e('Đang được ' . $heldByName . ' xử lý cho 1 đơn khác')
            . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" /></svg></div>';
    }
@endphp
<div class="selectable {{ $classes }}"
    style="{{ !$isSelectable ? 'pointer-events:none;opacity:0.55;' : 'cursor:pointer;' }}{{ $orderColor ? '--order-color:' . $orderColor . ';' : '' }}"
    data-room-id="{{ $room->id }}" data-timeslot-id="{{ $roomTimeSlot->timeSlot->id }}" data-date="{{ $date['date'] }}"
    data-iso-date="{{ $slotDateYmd }}"
    @click="toggleSlot($el)">{!! $overlayHtml !!}</div>
