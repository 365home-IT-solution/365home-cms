@php
    // Bật lên (Dashboard popup "Thông tin đơn" — xem routes/web.php orders/{id}/quick-info) để ẩn
    // 2 khối "Chi phí phát sinh"/nút "Phát sinh thêm.../Hoàn lại..." bên dưới — cả 2 chỉ hợp lý
    // trong NGỮ CẢNH ĐANG SỬA ĐƠN thật (nút "Hoàn lại..." gọi wire:click="save", KHÔNG hoạt động
    // khi card này bị render tĩnh ra popup xem nhanh, không có Livewire component thật đứng sau).
    // Mặc định false — KHÔNG đổi hành vi orderform hiện có (OrderForm.php không truyền biến này).
    $hideAdjustments = $hideAdjustments ?? false;

    // Loại dòng order_item "Phụ phí khách thêm" CŨ (extra_fee > 0, product_id = null — do luồng
    // đặt phòng cũ ProductDetail.php tự tạo riêng 1 dòng cho phụ thu khách). Phụ thu khách ở card
    // này LUÔN tính LIVE ở $guestSurchargeDetails/$totalGuestSurcharge bên dưới (dựa trên
    // guest_count của từng phòng) — giữ lại dòng này sẽ hiện thành 1 "khung giờ" giả VÀ cộng phụ
    // thu 2 LẦN. Cùng quy ước với OrderForm::computeOrderTotal() / ExtraChargeService::
    // calculateRealTotal() (->where('extra_fee', 0)) — 3 nơi tính tổng PHẢI cùng ra 1 số.
    $items = is_array($items)
        ? array_values(array_filter($items, fn ($item) => (float) ($item['extra_fee'] ?? 0) <= 0))
        : $items;
    $originalItems = isset($originalItems) && is_array($originalItems)
        ? array_values(array_filter($originalItems, fn ($item) => (float) ($item['extra_fee'] ?? 0) <= 0))
        : ($originalItems ?? null);

    // Mỗi khung giờ = 1 dòng order_item (giống đúng cách client/API tạo dữ liệu) — $slotCount
    // (số dòng) CHÍNH LÀ số khung giờ đã đặt, không cần suy ra từ đâu khác.
    $slotCount      = is_array($items) ? count($items) : 0;
    $totalSlotCount = $slotCount;

    // Chi tiết TỪNG khung giờ khách đã đặt — lấy thẳng checkin_date/checkout_date/price của
    // chính dòng đó (1 dòng = 1 khung giờ), không cần tra lại room_time_slots.
    $timeslotDetails = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (empty($item['checkin_date']) || empty($item['checkout_date'])) {
                continue;
            }

            $start = \Carbon\Carbon::parse($item['checkin_date']);
            $end   = \Carbon\Carbon::parse($item['checkout_date']);

            // 'discount' = giá GỐC của khung giờ (trước khuyến mãi riêng khung đó — xem
            // expandOrderItemsForPersistence()), 'price' = giá SAU khuyến mãi khung giờ (nếu có).
            // Khác nhau => khung này đang có khuyến mãi riêng, hiện rõ ra để không nhầm với giá gốc
            // dùng tính % "Đặt full ngày"/bulk (xem $discountConditionDetails phía dưới).
            $originalPrice = (float) ($item['discount'] ?? $item['price'] ?? 0);
            $finalPrice    = (float) ($item['price'] ?? 0);

            $timeslotDetails[] = [
                'room'           => $item['name'] ?? '',
                'start'          => $start,
                'end'            => $end,
                'over_night'     => $start->format('Y-m-d') !== $end->format('Y-m-d'),
                'price'          => $finalPrice,
                'original_price' => $originalPrice,
                'promo_pct'      => $originalPrice > $finalPrice ? round((1 - $finalPrice / $originalPrice) * 100) : 0,
            ];
        }

        usort($timeslotDetails, fn ($a, $b) => $a['start'] <=> $b['start']);
    }

    // Phát hiện style=2 (đặt theo ngày)
    $isStyle2 = false;

    // Load tất cả sản phẩm duy nhất để lấy bulk_discount_rules và room_config
    $productCache = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if ((int)($item['product_style'] ?? 0) === 2) { $isStyle2 = true; }
            $pid = $item['product_id'] ?? null;
            if ($pid && !isset($productCache[$pid])) {
                $prod = \Modules\Product\App\Models\Product::find($pid);
                if ($prod) {
                    $productCache[$pid] = $prod;
                    if ((int)($prod->styles ?? 1) === 2) { $isStyle2 = true; }
                }
            }
        }
    }

    // Nhóm items theo product_id (mỗi item = 1 khung giờ)
    $itemsByProduct = [];
    $noProductItems = [];
    if (is_array($items)) {
        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            if ($pid) {
                $itemsByProduct[$pid][] = $item;
            } else {
                $noProductItems[] = $item;
            }
        }
    }

    // Giá phòng + khuyến mãi khung giờ + giảm giá hệ thống (bulk HOẶC full_booking, loại trừ lẫn
    // nhau) + phụ thu khách — TẤT CẢ dùng qua OrderForm::resolveProductGroupPricing() (port chính
    // xác App\Http\Controllers\Api\Concerns\BuildsRoomBooking::computeBookingPreview(), engine
    // tính giá THẬT dùng cho mọi đơn tạo/sửa qua API — /api/admin/orders/preview) để card này
    // LUÔN ra đúng số khách/admin thấy ở API thật, không tự suy diễn công thức riêng nữa.
    $originalTotal          = 0;   // "Giá phòng gốc" = tổng giá GỐC thật của khung giờ (KHÔNG khuyến mãi/giảm giá) — khớp "slots_total" ở API preview
    $totalPromotionDiscount = 0;   // Khuyến mãi theo khung giờ (promo_discount) — CHỈ áp khi KHÔNG full-booking
    $totalSystemDiscount    = 0;   // Giảm giá hệ thống: bulk HOẶC full_booking (loại trừ lẫn nhau)
    $totalGuestSurcharge    = 0;
    $bulkDiscountDetails        = [];
    $fullBookingDiscountDetails = [];
    $guestSurchargeDetails      = [];
    // Snapshot ĐẦY ĐỦ kết quả resolveProductGroupPricing() theo từng phòng — dùng lại NGUYÊN VẸN
    // (không tính lại riêng) cho khối "Điều kiện giảm giá" trong "Xem chi tiết từng khung giờ" bên
    // dưới, để 2 nơi hiển thị LUÔN khớp nhau (tránh lệch như trước: khối đó tự suy luận riêng theo
    // bulk_discount_rules, không biết gì về full_booking_discount đang thắng thế).
    $productPricingDetails = [];

    foreach ($itemsByProduct as $pid => $groupItems) {
        $prod = $productCache[$pid] ?? null;

        $pricing = \Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm::resolveProductGroupPricing($groupItems, $prod);

        $originalTotal          += $pricing['base_price'];
        $totalPromotionDiscount += $pricing['promotion_discount'];
        $totalSystemDiscount    += $pricing['system_discount'];

        if ($pricing['guest_surcharge'] > 0 && $pricing['guest_surcharge_detail']) {
            $totalGuestSurcharge   += $pricing['guest_surcharge'];
            $guestSurchargeDetails[] = array_merge($pricing['guest_surcharge_detail'], [
                'room' => $prod->name ?? ($groupItems[0]['name'] ?? ''),
            ]);
        }

        if ($pricing['discount_type'] === 'full_booking') {
            $fullBookingDiscountDetails[] = [
                'dates'  => $pricing['full_booking_dates'],
                'label'  => $prod?->full_booking_discount,
                'amount' => $pricing['system_discount'],
            ];
        } elseif ($pricing['discount_type'] === 'bulk') {
            $bulkDiscountDetails[] = [
                'slots'  => count($groupItems),
                'pct'    => $pricing['discount_pct'],
                'amount' => $pricing['system_discount'],
            ];
        }

        if ($prod) {
            $productPricingDetails[] = [
                'room'       => $prod->name ?? ($groupItems[0]['name'] ?? ''),
                'productId'  => $pid,
                'slotCount'  => count($groupItems),
                'groupItems' => $groupItems,
                'pricing'    => $pricing,
                'product'    => $prod,
            ];
        }
    }

    foreach ($noProductItems as $item) {
        $originalTotal += (float)($item['price'] ?? 0);
    }

    // Khi edit đơn CỌC đã tồn tại: dùng discount thực tế từ DB (bao gồm KM khung giờ)
    // record->amount - full_amount = tổng giảm giá trên slot (bất biến dù service thay đổi).
    // full_amount là tổng giá CỐ ĐỊNH của đơn (đặt cọc hay không cũng vậy) — đơn thanh toán đủ
    // (deposit_percent = null) KHÔNG BAO GIỜ set full_amount (luôn rỗng), nên KHÔNG được dùng
    // full_amount để suy discount cho đơn loại này (trước đây coi full_amount rỗng = 0 khiến
    // discount = gần như toàn bộ amount, sai hoàn toàn) — đơn thường luôn dùng discount tính LIVE
    // từ resolveProductGroupPricing().
    $hasRecord         = isset($record) && $record && $record->id;
    $actualSlotDiscount = 0;
    $useActualDiscount  = false;
    if ($hasRecord && (int)($record->amount ?? 0) > 0) {
        $recordDepositPct = $record->deposit_percent !== null ? (int)$record->deposit_percent : null;
        if ($recordDepositPct !== null && $recordDepositPct > 0 && $recordDepositPct < 100 && (int)($record->full_amount ?? 0) > 0) {
            // full_amount CỐ ĐỊNH = tổng giá thật — dùng thẳng, không "reconstruct" bằng cách chia
            // ngược cho %cọc nữa (công thức cũ coi full_amount là tiền cọc, sai — xem ghi chú ở
            // khối "THÔNG TIN CỌC" bên dưới).
            $recordRealTotal    = (int) $record->full_amount;
            $actualSlotDiscount = max(0, (int)$record->amount - $recordRealTotal);
            if ($actualSlotDiscount > 0) { $useActualDiscount = true; }
        }
    }

    $totalDiscount      = $totalPromotionDiscount + $totalSystemDiscount;
    $effectiveDiscount  = $useActualDiscount ? $actualSlotDiscount : $totalDiscount;
    $totalAfterBulk      = max(0, $originalTotal - $effectiveDiscount);
    $hasDiscount          = $effectiveDiscount > 0;

    // Dịch vụ: ưu tiên form state (live), fallback DB
    $serviceItems    = collect();
    $serviceTotal    = 0;
    $useFormServices = isset($servicesFormState) && is_array($servicesFormState) && count($servicesFormState) > 0;

    if ($useFormServices) {
        foreach ($servicesFormState as $s) {
            if (!empty($s['service_name']) || !empty($s['service_id'])) {
                $name     = $s['service_name'] ?? '';
                $qty      = (int)($s['quantity'] ?? 1);
                $price    = (float)($s['price'] ?? 0);
                $subtotal = (float)($s['subtotal'] ?? ($price * $qty));
                $serviceItems->push((object)[
                    'service_name' => $name,
                    'quantity'     => $qty,
                    'price'        => $price,
                    'subtotal'     => $subtotal,
                ]);
                $serviceTotal += $subtotal;
            }
        }
    } elseif (isset($record) && $record && method_exists($record, 'services')) {
        $record->load('services');
        $serviceItems = $record->services ?? collect();
        $serviceTotal = $serviceItems->sum('subtotal');
    }

    // Phụ thu admin gõ tay ở field 'surcharge' (xem OrderForm.php) — cộng thẳng vào tổng, giống
    // cách dịch vụ thêm được cộng vào.
    $manualSurcharge = (float) ($surcharge ?? 0);

    $computedLiveTotal = $totalAfterBulk + $totalGuestSurcharge + $serviceTotal + $manualSurcharge;

    // 'displayTotal' (nếu được truyền vào — xem OrderForm.php) = giá trị HIỆN TẠI của field 'amount'
    // thật sự sẽ lưu vào đơn, kể cả khi admin đã gõ đè tay khác với số hệ thống tự tính — số cuối
    // cùng hiển thị ở đây PHẢI luôn khớp với ô "Tổng tiền đơn", không tự tính riêng 1 số khác.
    $finalTotal = $displayTotal ?? $computedLiveTotal;

    // Đơn paid có extra_charge: extra_charge_amount là phần đã phát sinh trước đó. Vẫn giữ tổng live
    // theo form hiện tại để khi admin thêm dịch vụ/phòng mới, card thấy ngay phần chênh lệch mới.
    $extraChargeAmt  = $hasRecord ? (int)($record->extra_charge_amount ?? 0) : 0;
    $isPaidWithExtra = $extraChargeAmt > 0 && $hasRecord && $record->deposit_percent === null;
    $canPreviewPriceAdjustment = $hasRecord && in_array($record->status, ['paid', 'deposit'], true);

    // Chênh lệch SỐNG khi admin đang thêm/bớt khung giờ/dịch vụ (CHƯA bấm Lưu).
    // Nếu đã có phát sinh cũ, so với full_amount + extra_charge_amount để chỉ hiện phần thay đổi mới.
    $liveDiff = null;
    $liveDiffLabelPositive = 'Phát sinh thêm so với lúc đặt';
    $liveDiffLabelNegative = 'Hoàn lại so với lúc đặt';
    if ($canPreviewPriceAdjustment) {
        $bookingBaselineAmount = (int) ($record->full_amount ?? 0);
        if ($isPaidWithExtra) {
            $bookingBaselineAmount += $extraChargeAmt;
            $liveDiffLabelPositive = 'Phát sinh thêm so với hiện tại';
            $liveDiffLabelNegative = 'Giảm so với hiện tại';
        }

        $liveDiffValue = (int) $computedLiveTotal - $bookingBaselineAmount;
        if ($bookingBaselineAmount > 0 && $liveDiffValue !== 0) {
            $liveDiff = $liveDiffValue;
        }
    }

    // Điều kiện giảm giá — dùng lại NGUYÊN kết quả $pricing đã tính ở $productPricingDetails phía
    // trên (KHÔNG tự suy luận lại riêng theo bulk_discount_rules như trước — cách cũ không biết
    // full_booking_discount đang thắng thế, khiến hiển thị "đạt giảm 10%" dù hệ thống thực tế đã
    // áp 35% "Đặt full ngày", 2 mức loại trừ lẫn nhau — xem resolveProductGroupPricing()).
    $discountConditionDetails = [];
    if (!$isStyle2) {
        foreach ($productPricingDetails as $entry) {
            $prod       = $entry['product'];
            $pid        = $entry['productId'];
            $groupItems = $entry['groupItems'];
            $slotCount  = $entry['slotCount'];
            $pricing    = $entry['pricing'];

            // Chỉ 1 khung giờ thì KHÔNG thể đạt bất kỳ điều kiện giảm giá nào (bulk yêu cầu tối
            // thiểu 2 khung, "Đặt full ngày" yêu cầu HẾT khung giờ trong ngày — thực tế luôn > 1) —
            // ẩn hẳn khối này thay vì hiện "chưa đạt mức giảm nào" không cần thiết.
            if ($slotCount < 2) continue;

            $bulkRules = collect($prod->bulk_discount_rules ?? [])
                ->map(fn ($r) => ['slots' => (int)($r['slots'] ?? 0), 'discount' => (float)($r['discount'] ?? 0)])
                ->filter(fn ($r) => $r['slots'] >= 2)
                ->sortBy('slots')
                ->values();

            $hasFullBookingRule = filled($prod->full_booking_discount);

            if ($bulkRules->isEmpty() && !$hasFullBookingRule) continue;

            $bulkAchieved = $bulkRules->filter(fn ($r) => $slotCount >= $r['slots'])->last();
            $bulkNext     = $bulkRules->first(fn ($r) => $r['slots'] > $slotCount);

            $isFullBookingApplied = $pricing['discount_type'] === 'full_booking';

            // Tiến độ "Đặt full ngày" theo TỪNG ngày trong nhóm (chỉ cần khi CHƯA đạt full ngày —
            // nếu đã đạt thì discount_type đã là 'full_booking' ở trên, khỏi cần tính tiến độ nữa).
            $fullBookingProgress = [];
            if ($hasFullBookingRule && !$isFullBookingApplied) {
                $totalSlotsInRoom = \Modules\Product\App\Models\RoomTimeSlot::where('room_id', $pid)->whereNull('date')->count();
                if ($totalSlotsInRoom > 0) {
                    $itemsByDate = collect($groupItems)
                        ->filter(fn ($i) => !empty($i['checkin_date']))
                        ->groupBy(fn ($i) => \Carbon\Carbon::parse($i['checkin_date'])->format('Y-m-d'));

                    foreach ($itemsByDate as $date => $dateItems) {
                        $fullBookingProgress[] = [
                            'date'    => \Carbon\Carbon::parse($date),
                            'count'   => $dateItems->count(),
                            'total'   => $totalSlotsInRoom,
                        ];
                    }
                }
            }

            // Số tiền GỐC dùng làm cơ sở tính % — khác nhau giữa 2 loại giảm giá (xem
            // resolveProductGroupPricing()): bulk tính trên (giá gốc − khuyến mãi khung giờ), còn
            // "Đặt full ngày" tính trên TOÀN BỘ giá gốc của phòng đó trong đơn (mọi ngày, KHÔNG trừ
            // khuyến mãi khung giờ trước) — vì hàm return SỚM ngay khi phát hiện full-booking, khuyến
            // mãi khung giờ (PromotionCalculator) KHÔNG được cộng dồn nữa (2 loại loại trừ lẫn nhau).
            $bulkCalcBase = $pricing['base_price'] - $pricing['promotion_discount'];

            // Khi ĐÃ đạt "Đặt full ngày": mỗi dòng trong "Xem chi tiết từng khung giờ" ở trên vẫn
            // hiện $item['price'] (giá ĐÃ áp khuyến mãi khung giờ riêng, tính lúc mở lưới chọn) — nên
            // "Tổng tiền khung giờ" cộng dồn các dòng đó có thể THẤP HƠN giá gốc dùng tính % ở đây.
            // Số chênh lệch = khuyến mãi khung giờ mà hệ thống KHÔNG áp dụng do đã thắng "full ngày".
            $fullBookingForfeitedPromo = 0;
            if ($isFullBookingApplied) {
                $groupPriceSum = collect($groupItems)->sum(fn ($i) => (float) ($i['price'] ?? 0));
                $fullBookingForfeitedPromo = max(0, $pricing['base_price'] - $groupPriceSum);
            }

            // Số tiền giảm "NẾU" tính theo số khung giờ thay vì "Đặt full ngày" — chỉ để SO SÁNH
            // bằng số liệu cụ thể (thay vì chỉ nói suông "2 loại không cộng dồn"), KHÔNG phải số
            // thực tế được áp dụng.
            $bulkWouldBeAmount = $bulkAchieved ? round($bulkCalcBase * $bulkAchieved['discount'] / 100) : null;

            $discountConditionDetails[] = [
                'room'                       => $entry['room'],
                'slotCount'                  => $slotCount,
                'bulkRules'                  => $bulkRules,
                'bulkAchieved'               => $bulkAchieved,
                'bulkNext'                   => $bulkNext,
                'bulkCalcBase'               => $bulkCalcBase,
                'bulkWouldBeAmount'          => $bulkWouldBeAmount,
                'hasFullBookingRule'         => $hasFullBookingRule,
                'fullBookingLabel'           => $prod->full_booking_discount,
                'isFullBookingApplied'       => $isFullBookingApplied,
                'fullBookingForfeitedPromo'  => $fullBookingForfeitedPromo,
                'fullBookingDates'      => $pricing['full_booking_dates'],
                'fullBookingAmount'     => $pricing['system_discount'],
                'fullBookingCalcBase'   => $pricing['base_price'],
                'fullBookingProgress'   => $fullBookingProgress,
            ];
        }
    }

    // Số khách đã đặt — hệ thống hiện giới hạn 1 phòng/đơn nên guest_count giống nhau ở mọi dòng,
    // lấy thẳng dòng đầu để hiển thị (mục "Số lượng khách" theo yêu cầu, kể cả khi KHÔNG vượt
    // ngưỡng miễn phí — lúc đó chỉ hiện số khách, không hiện dòng phụ thu).
    $totalGuestCount = (is_array($items) && count($items) > 0) ? (int) ($items[0]['guest_count'] ?? 0) : 0;
@endphp

{{--
    Bảng màu "boulder" (trung tính, sang trọng) — dùng CHUNG với order-form.css/access-code-info,
    xem style="..." bên dưới. Màu NGỮ NGHĨA thật (xanh=đã thu/đã trả đủ, đỏ=còn nợ/cảnh báo, hổ
    phách=đang chờ xử lý) vẫn giữ nguyên — CHỈ trung hoà các mảng màu KHÔNG mang nghĩa cảnh báo gì
    (dịch vụ/giảm giá/ghi chú trước đây tô cam/xanh dương/xanh da trời tuỳ ý) về đúng 1 bảng màu
    trung tính duy nhất, tạo cảm giác gọn gàng/cao cấp hơn thay vì nhiều màu sắc rải rác.
--}}
<div style="font-family: inherit;">

    @if($slotCount === 0)
        {{-- Empty state --}}
        <div style="text-align:center; padding: 2rem 1rem;">
            <div style="font-size:1.5rem; font-weight:700; color:var(--boulder-80, #E5E7EB); margin-bottom:0.25rem;">0 VNĐ</div>
            <p style="color:var(--boulder-50, #737882); font-size:0.8125rem;">Chưa có phòng nào được chọn</p>
        </div>
    @else

        {{-- ===== CHI TIẾT TỪNG KHUNG GIỜ (thẻ collapse) ===== --}}
        @if(!$isStyle2 && count($timeslotDetails) > 0)
        @php
            $timeslotDetailsTotal = collect($timeslotDetails)->sum('price');
            // Gom theo ngày (giống lưới chọn khung giờ bên trái) thay vì lặp lại ngày trên
            // từng dòng — dễ đối chiếu khi 1 đơn có nhiều khung giờ/nhiều ngày.
            $timeslotDetailsByDate = collect($timeslotDetails)->groupBy(fn ($d) => $d['start']->format('Y-m-d'));
            // Thứ viết tắt tiếng Việt (CN/T2..T7) theo dayOfWeek — cùng quy ước với
            // timeslot-grid-table.blade.php ($weekdayShortVi).
            $weekdayShortVi = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
        @endphp
        <details open style="margin-bottom:0.75rem; background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem;">
            <summary style="cursor:pointer; padding:0.55rem 0.75rem; font-size:0.75rem; font-weight:600; color:var(--boulder-30, #363B45); display:flex; align-items:center; gap:0.35rem; list-style:none;">
                <x-heroicon-o-clock style="width:0.875rem; height:0.875rem; color:var(--boulder-50, #737882);" />
                Xem chi tiết từng khung giờ ({{ count($timeslotDetails) }})
            </summary>
            <div style="padding:0 0.75rem 0.6rem;">
                @foreach($timeslotDetailsByDate as $dayDetails)
                @php $firstOfDay = $dayDetails->first(); @endphp
                <div style="{{ !$loop->first ? 'margin-top:0.5rem;' : '' }}">
                    <div style="font-size:0.7rem; font-weight:700; color:var(--boulder-50, #737882); text-transform:uppercase; letter-spacing:0.03em; padding:0.2rem 0;">
                        {{ $weekdayShortVi[$firstOfDay['start']->dayOfWeek] }}, {{ $firstOfDay['start']->format('d/m/Y') }}
                    </div>
                    @foreach($dayDetails as $detail)
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:0.3rem 0 0.3rem 0.5rem;{{ !$loop->last ? ' border-bottom:1px dashed var(--boulder-80, #E5E7EB);' : '' }}">
                        <span style="font-size:0.75rem; color:var(--boulder-30, #363B45);">
                            <span style="font-weight:600;">{{ $detail['start']->format('H:i') }}–{{ $detail['end']->format('H:i') }}</span>
                            @if($detail['over_night'])
                                <span style="color:var(--boulder-50, #737882);"> (qua đêm — {{ $detail['end']->format('d/m/Y') }})</span>
                            @endif
                            @if(count($itemsByProduct) > 1 || $detail['room'])
                                <span style="color:var(--boulder-50, #737882);">— {{ $detail['room'] }}</span>
                            @endif
                        </span>
                        <span style="text-align:right; white-space:nowrap;">
                            @if($detail['promo_pct'] > 0)
                            <span style="font-size:0.68rem; color:var(--boulder-50, #737882); text-decoration:line-through; margin-right:0.3rem;">
                                {{ number_format($detail['original_price'], 0, ',', '.') }} đ
                            </span>
                            <span style="font-size:0.65rem; font-weight:700; color:#15803d; background:#dcfce7; border-radius:0.25rem; padding:0.05rem 0.3rem; margin-right:0.3rem;">
                                -{{ $detail['promo_pct'] }}%
                            </span>
                            @endif
                            <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">
                                {{ number_format($detail['price'], 0, ',', '.') }} đ
                            </span>
                        </span>
                    </div>
                    @endforeach
                </div>
                @endforeach
                <div style="display:flex; justify-content:space-between; align-items:center; padding-top:0.45rem; margin-top:0.4rem; border-top:1px solid var(--boulder-80, #E5E7EB);">
                    <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-30, #363B45);">Tổng tiền khung giờ</span>
                    <span style="font-size:0.8rem; font-weight:800; color:var(--boulder-20, #272B35); white-space:nowrap;">
                        {{ number_format($timeslotDetailsTotal, 0, ',', '.') }} đ
                    </span>
                </div>

                {{-- Điều kiện giảm giá — hiển thị RÕ mức "Đặt full ngày" (nếu đạt, LUÔN ưu tiên hơn
                     giảm theo số khung — 2 loại loại trừ lẫn nhau) hoặc mức giảm theo số khung giờ
                     đã đạt/kế tiếp, KÈM số tiền gốc dùng làm cơ sở tính %, để admin hiểu ngay vì sao
                     có/không có giảm giá cho đơn này (đặc biệt với đơn đã thanh toán có phát sinh —
                     phần "Ưu đãi & giảm giá" phía trên bị ẩn nên đây là chỗ DUY NHẤT còn hiện info
                     này). --}}
                @if(!$isStyle2 && count($discountConditionDetails) > 0)
                <div style="margin-top:0.5rem; padding-top:0.5rem; border-top:1px dashed var(--boulder-80, #E5E7EB);">
                    <div style="display:flex; align-items:center; gap:0.35rem; margin-bottom:0.35rem;">
                        <x-heroicon-o-tag style="width:0.8rem; height:0.8rem; color:var(--boulder-50, #737882); flex-shrink:0;" />
                        <span style="font-size:0.7rem; font-weight:600; color:var(--boulder-30, #363B45);">Điều kiện giảm giá</span>
                    </div>
                    @foreach($discountConditionDetails as $cond)
                    <div style="{{ !$loop->first ? 'margin-top:0.5rem; padding-top:0.5rem; border-top:1px dashed var(--boulder-80, #E5E7EB);' : '' }}">
                        @if(count($discountConditionDetails) > 1)
                        <div style="font-size:0.7rem; font-weight:600; color:var(--boulder-30, #363B45); margin-bottom:0.15rem;">{{ $cond['room'] }}</div>
                        @endif

                        @if($cond['isFullBookingApplied'])
                            {{-- Đã đạt "Đặt full ngày" — mức này THẮNG, bulk_discount_rules không áp dụng nữa. --}}
                            <div style="font-size:0.7rem; color:#15803d; line-height:1.5;">
                                🎉 Đã đạt <strong>"Đặt full ngày"</strong> ({{ collect($cond['fullBookingDates'])->map(fn($d) => \Carbon\Carbon::parse($d)->format('d/m')) ->implode(', ') }}) — giảm <strong>{{ $cond['fullBookingLabel'] }}</strong>
                                trên giá gốc {{ number_format($cond['fullBookingCalcBase'], 0, ',', '.') }} đ = -{{ number_format($cond['fullBookingAmount'], 0, ',', '.') }} đ.
                            </div>
                            <div style="font-size:0.68rem; color:var(--boulder-50, #737882); margin-top:0.2rem;">
                                Bỏ qua các loại giảm giá khác khi đã đạt "Đặt full ngày":
                                @if($cond['bulkWouldBeAmount'] !== null)
                                    giảm theo số khung -{{ number_format($cond['bulkWouldBeAmount'], 0, ',', '.') }} đ{{ $cond['fullBookingForfeitedPromo'] > 0 ? ',' : '.' }}
                                @endif
                                @if($cond['fullBookingForfeitedPromo'] > 0)
                                    khuyến mãi khung giờ -{{ number_format($cond['fullBookingForfeitedPromo'], 0, ',', '.') }} đ.
                                @endif
                            </div>
                        @else
                            {{-- Chưa đạt full ngày — hiện mức giảm theo số khung giờ (nếu có cấu hình) --}}
                            @if($cond['bulkRules']->isNotEmpty())
                            <div style="font-size:0.7rem; color:var(--boulder-50, #737882); line-height:1.5;">
                                Đang đặt <strong style="color:var(--boulder-30, #363B45);">{{ $cond['slotCount'] }} khung giờ</strong>
                                @if($cond['bulkAchieved'])
                                    — đã đạt mức giảm <strong style="color:#15803d;">{{ $cond['bulkAchieved']['discount'] }}%</strong> (từ {{ $cond['bulkAchieved']['slots'] }} khung)
                                    trên giá gốc {{ number_format($cond['bulkCalcBase'], 0, ',', '.') }} đ (đã trừ khuyến mãi khung giờ nếu có).
                                @else
                                    — chưa đạt mức giảm nào.
                                @endif
                            </div>
                            @if($cond['bulkNext'])
                            <div style="font-size:0.7rem; color:#b45309; margin-top:0.1rem;">
                                Đặt thêm {{ $cond['bulkNext']['slots'] - $cond['slotCount'] }} khung nữa để được giảm {{ $cond['bulkNext']['discount'] }}% (đủ {{ $cond['bulkNext']['slots'] }} khung).
                            </div>
                            @endif
                            <div style="font-size:0.68rem; color:var(--boulder-50, #737882); margin-top:0.25rem;">
                                @foreach($cond['bulkRules'] as $rule)
                                    <span style="{{ $cond['bulkAchieved'] && $cond['bulkAchieved']['slots'] === $rule['slots'] ? 'font-weight:700; color:#15803d;' : '' }}">{{ $rule['slots'] }} khung → {{ $rule['discount'] }}%</span>{{ !$loop->last ? ' · ' : '' }}
                                @endforeach
                            </div>
                            @endif

                            {{-- Tiến độ "Đặt full ngày" theo từng ngày — cho biết còn thiếu bao nhiêu
                                 khung để đạt mức giảm cao hơn (thường lớn hơn nhiều so với bulk). --}}
                            @if($cond['hasFullBookingRule'] && count($cond['fullBookingProgress']) > 0)
                            <div style="font-size:0.68rem; color:var(--boulder-50, #737882); margin-top:{{ $cond['bulkRules']->isNotEmpty() ? '0.4rem' : '0' }};">
                                "Đặt full ngày" (giảm {{ $cond['fullBookingLabel'] }}) — cần chọn HẾT khung giờ trong 1 ngày:
                                @foreach($cond['fullBookingProgress'] as $p)
                                    {{ $p['date']->format('d/m') }}: {{ $p['count'] }}/{{ $p['total'] }} khung{{ !$loop->last ? ' · ' : '' }}
                                @endforeach
                            </div>
                            @endif
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </details>
        @endif

        {{-- ===== SỐ KHÁCH ===== --}}
        @if($totalGuestCount > 0)
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-user-group style="width:1rem; height:1rem; color:var(--boulder-50, #737882);" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--boulder-50, #737882);">
                    Số khách
                </span>
            </div>
            <div style="background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem; padding:0.5rem 0.75rem;">
                <div style="display:flex; justify-content:space-between; align-items:center;{{ $totalGuestSurcharge > 0 ? ' padding-bottom:0.35rem; border-bottom:1px dashed var(--boulder-80, #E5E7EB);' : '' }}">
                    <span style="font-size:0.75rem; color:var(--boulder-30, #363B45);">Số khách đã đặt</span>
                    <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">{{ $totalGuestCount }} khách</span>
                </div>
                @if($totalGuestSurcharge > 0)
                @foreach($guestSurchargeDetails as $detail)
                <div style="display:flex; justify-content:space-between; align-items:center; padding-top:0.35rem;">
                    <span style="font-size:0.75rem; color:var(--boulder-50, #737882); display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-user-plus style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Vượt {{ $detail['max_free'] }} khách miễn phí — phụ thu {{ $detail['extra_guests'] }} người{{ ($detail['nights'] ?? 1) > 1 ? ' × ' . $detail['nights'] . ' đêm' : '' }}{{ !empty($detail['room']) ? ' — ' . $detail['room'] : '' }}
                    </span>
                    <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35); white-space:nowrap; margin-left:0.5rem;">
                        +{{ number_format($detail['total'], 0, ',', '.') }} đ
                    </span>
                </div>
                @endforeach
                @endif
            </div>
        </div>
        @endif

        {{-- ===== DỊCH VỤ ===== --}}
        @if($serviceItems->count() > 0)
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-shopping-bag style="width:1rem; height:1rem; color:var(--boulder-50, #737882);" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--boulder-50, #737882);">
                    Dịch vụ thêm ({{ $serviceItems->count() }})
                </span>
            </div>

            <div style="background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem; padding:0.5rem 0.75rem;">
                @foreach($serviceItems as $svc)
                <div style="display:flex; justify-content:space-between; align-items:center; padding:0.35rem 0;{{ !$loop->last ? ' border-bottom:1px dashed var(--boulder-80, #E5E7EB);' : '' }}">
                    <div style="display:flex; align-items:center; gap:0.35rem; min-width:0;">
                        <x-heroicon-o-check-circle style="width:0.75rem; height:0.75rem; color:var(--boulder-50, #737882); flex-shrink:0;" />
                        <span style="font-size:0.75rem; color:var(--boulder-30, #363B45); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            {{ $svc->service_name }}
                        </span>
                        <span style="font-size:0.7rem; color:var(--boulder-50, #737882); background:var(--boulder-95, #F9FAFB); border:1px solid var(--boulder-80, #E5E7EB); border-radius:9999px; padding:0 0.35rem; white-space:nowrap; flex-shrink:0;">
                            {{ number_format((float)$svc->price, 0, ',', '.') }}đ × {{ $svc->quantity }}
                        </span>
                    </div>
                    <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35); white-space:nowrap; flex-shrink:0; margin-left:0.5rem;">
                        +{{ number_format((float)$svc->subtotal, 0, ',', '.') }} đ
                    </span>
                </div>
                @endforeach

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px solid var(--boulder-80, #E5E7EB);">
                    <span style="font-size:0.75rem; font-weight:600; color:var(--boulder-30, #363B45);">Tổng dịch vụ</span>
                    <span style="font-size:0.8125rem; font-weight:700; color:var(--boulder-20, #272B35);">
                        +{{ number_format($serviceTotal, 0, ',', '.') }} đ
                    </span>
                </div>
            </div>
        </div>
        @endif

        {{-- ===== ƯU ĐÃI & GIẢM GIÁ ===== --}}
        @if($hasDiscount && !$isStyle2 && !$isPaidWithExtra)
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-receipt-percent style="width:1rem; height:1rem; color:var(--boulder-50, #737882);" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--boulder-50, #737882);">
                    Ưu đãi &amp; giảm giá
                </span>
            </div>
            <div style="background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem; padding:0.5rem 0.75rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:0.35rem; border-bottom:1px dashed var(--boulder-80, #E5E7EB);">
                    <span style="font-size:0.75rem; color:var(--boulder-50, #737882);">Giá phòng gốc</span>
                    <span style="font-size:0.75rem; color:var(--boulder-50, #737882); text-decoration:line-through;">
                        {{ number_format($originalTotal, 0, ',', '.') }} đ
                    </span>
                </div>

                @if($useActualDiscount)
                <div style="display:flex; justify-content:space-between; align-items:center; padding-top:0.35rem;">
                    <span style="font-size:0.75rem; color:var(--boulder-30, #363B45);">Giảm (KM + chiết khấu thực tế)</span>
                    <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">
                        -{{ number_format($effectiveDiscount, 0, ',', '.') }} đ
                    </span>
                </div>
                @else
                    @if($totalPromotionDiscount > 0)
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-top:0.35rem;">
                        <span style="font-size:0.75rem; color:var(--boulder-30, #363B45);">Khuyến mãi khung giờ</span>
                        <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">
                            -{{ number_format($totalPromotionDiscount, 0, ',', '.') }} đ
                        </span>
                    </div>
                    @endif
                    @foreach($fullBookingDiscountDetails as $detail)
                    <div style="padding-top:0.35rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:0.75rem; color:var(--boulder-30, #363B45); font-weight:600;">
                                🎉 Đặt full ngày{{ $detail['label'] ? ' (giảm ' . $detail['label'] . ')' : '' }}
                            </span>
                            <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">
                                -{{ number_format($detail['amount'], 0, ',', '.') }} đ
                            </span>
                        </div>
                        @if(!empty($detail['dates']))
                        <div style="font-size:0.7rem; color:var(--boulder-50, #737882); margin-top:0.1rem;">
                            Áp dụng ngày: {{ collect($detail['dates'])->map(fn($d) => \Carbon\Carbon::parse($d)->format('d/m/Y'))->implode(', ') }}
                        </div>
                        @endif
                    </div>
                    @endforeach
                    @foreach($bulkDiscountDetails as $detail)
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-top:0.35rem;">
                        <span style="font-size:0.75rem; color:var(--boulder-30, #363B45);">
                            Đặt {{ $detail['slots'] }} khung giờ — giảm {{ $detail['pct'] }}%
                        </span>
                        <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35);">
                            -{{ number_format($detail['amount'], 0, ',', '.') }} đ
                        </span>
                    </div>
                    @endforeach
                @endif

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px solid var(--boulder-80, #E5E7EB);">
                    <span style="font-size:0.75rem; font-weight:600; color:var(--boulder-30, #363B45);">Sau giảm giá</span>
                    <span style="font-size:0.8125rem; font-weight:700; color:var(--boulder-20, #272B35);">
                        {{ number_format($totalAfterBulk, 0, ',', '.') }} đ
                    </span>
                </div>
            </div>
        </div>
        @endif

        {{-- ===== CHI PHÍ PHÁT SINH (đơn đã paid, đã có khoản phát sinh từ trước) ===== --}}
        @if($isPaidWithExtra && !$hideAdjustments)
        @php
            $extraIsPaid = !is_null($record->extra_charge_paid_at);
            $extraMethod = $record->extra_charge_payment_method ?? null;
            $extraLabel  = $extraIsPaid
                ? ('Đã thu' . match ($extraMethod) {
                    'cod' => ' (tiền mặt)',
                    'bank_transfer' => ' (chuyển khoản)',
                    default => ' (QR)',
                })
                : 'Chưa thanh toán';
            $extraColor  = $extraIsPaid ? '#15803d' : '#dc2626';
        @endphp
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-banknotes style="width:1rem; height:1rem; color:var(--boulder-50, #737882);" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--boulder-50, #737882);">
                    Chi phí phát sinh
                </span>
            </div>
            <div style="background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem; padding:0.5rem 0.75rem;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.75rem; color:{{ $extraColor }}; display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-plus-circle style="width:0.75rem; height:0.75rem;" />
                        Phát sinh — {{ $extraLabel }}
                    </span>
                    <span style="font-size:0.75rem; font-weight:700; color:{{ $extraColor }};">
                        +{{ number_format($extraChargeAmt, 0, ',', '.') }} đ
                    </span>
                </div>
            </div>
        </div>
        @endif

        {{-- ===== PHỤ THU (gõ tay) ===== --}}
        @if($manualSurcharge > 0)
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; padding:0.5rem 0.75rem; background:var(--boulder-99, #FBFCFD); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.75rem;">
            <span style="font-size:0.75rem; color:var(--boulder-50, #737882);">Phụ thu (nhập tay)</span>
            <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-30, #363B45);">
                +{{ number_format($manualSurcharge, 0, ',', '.') }} đ
            </span>
        </div>
        @endif

        {{-- ===== TỔNG CUỐI ===== --}}
        <div style="background:linear-gradient(135deg, var(--boulder-20, #272B35) 0%, var(--boulder-30, #363B45) 100%); border-radius:0.875rem; padding:0.85rem 1.1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:0.7rem; color:rgba(255,255,255,0.65); font-weight:500; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:0.15rem;">
                        Tổng thanh toán
                    </div>
                    <div style="font-size:0.7rem; color:rgba(255,255,255,0.5);">
                        {{ $slotCount }} phòng{{ $serviceItems->count() > 0 ? ' + ' . $serviceItems->count() . ' dịch vụ' : '' }}
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.375rem; font-weight:800; color:#ffffff; line-height:1.2;">
                        {{ number_format($finalTotal, 0, ',', '.') }}
                    </div>
                    <div style="font-size:0.7rem; color:rgba(255,255,255,0.6); font-weight:600;">VNĐ</div>
                </div>
            </div>
        </div>

        @if($liveDiff !== null && !$hideAdjustments)
        {{-- Bấm THẲNG vào dòng chênh lệch này để Lưu đơn ngay — trước đây admin phải tự tìm nút
             "Lưu" ở nơi khác trên trang rồi mới thấy panel "Phát sinh thêm/Hoàn tiền chưa xử lý"
             hiện ra ở tab Thông tin thanh toán, cảm giác dư thừa 1 bước. Bấm ở đây gọi thẳng
             wire:click="save" (action Lưu chuẩn của Filament EditRecord) — sau khi lưu xong,
             EditOrder::handlePriceDiff() tự tạo QR/ghi khoản phát sinh và panel tương ứng hiện
             NGAY trên cùng trang, không cần thao tác gì thêm. Giữ màu hổ phách/xanh lá NGUYÊN
             VẸN — đây là cảnh báo hành động thật (cần thu thêm/cần hoàn), không phải chỗ trung
             hoà màu theo bảng "boulder". --}}
        <button
            type="button"
            wire:key="live-diff-save-btn-{{ $hasRecord ? $record->id : 'new' }}"
            wire:click="save"
            wire:loading.attr="disabled"
            wire:target="save"
            style="display:flex; width:100%; justify-content:space-between; align-items:center; margin-top:0.5rem; padding:0.5rem 0.75rem; border-radius:0.625rem; background:{{ $liveDiff > 0 ? '#fffbeb' : '#f0fdf4' }}; border:1px solid {{ $liveDiff > 0 ? '#fde68a' : '#bbf7d0' }}; cursor:pointer; font-family:inherit; text-align:left;"
            onmouseenter="this.style.filter='brightness(0.97)'"
            onmouseleave="this.style.filter='none'"
        >
            <span style="font-size:0.75rem; font-weight:600; color:{{ $liveDiff > 0 ? '#b45309' : '#15803d' }}; display:flex; align-items:center; gap:0.3rem;">
                <x-heroicon-o-exclamation-circle style="width:0.875rem; height:0.875rem;" />
                {{ $liveDiff > 0 ? $liveDiffLabelPositive : $liveDiffLabelNegative }}
                <span wire:loading wire:target="save" style="font-style:italic; font-weight:400;">(đang lưu...)</span>
            </span>
            <span style="display:flex; align-items:center; gap:0.4rem;">
                <span style="font-size:0.85rem; font-weight:800; color:{{ $liveDiff > 0 ? '#b45309' : '#15803d' }};">
                    {{ $liveDiff > 0 ? '+' : '' }}{{ number_format($liveDiff, 0, ',', '.') }} đ
                </span>
                <span style="font-size:0.65rem; font-weight:600; color:{{ $liveDiff > 0 ? '#b45309' : '#15803d' }}; background:rgba(255,255,255,.6); border-radius:9999px; padding:0.15rem 0.5rem; white-space:nowrap;">
                    Bấm để lưu &amp; xử lý
                </span>
            </span>
        </button>
        @endif

        {{-- ===== THÔNG TIN CỌC (chỉ hiện với style=2 khi có cọc) ===== --}}
        @if(isset($record) && $record && $record->deposit_percent !== null && $isStyle2)
        @php
            // full_amount CỐ ĐỊNH = tổng giá thật của đơn (KHÔNG phải số tiền đã cọc) — số cọc phải
            // tính XUÔI full_amount * %cọc / 100 (khớp Order::depositDueAmount()), không chia ngược
            // như trước đây (coi full_amount là tiền cọc rồi suy ngược ra tổng — sai, ra tổng gấp
            // đôi thực tế khi %cọc = 50).
            $depPct      = (int) $record->deposit_percent;
            $realTotal   = (int) $record->full_amount;
            $depositAmt  = $record->depositDueAmount();
            $depExtra    = (int) ($record->extra_charge_amount ?? 0);
            $baseRemain  = max(0, $realTotal - $depositAmt);
            $remain2     = $baseRemain + $depExtra;
            $isFullPaid  = $record->status === 'paid';
            $isDepPaid   = in_array($record->status, ['deposit', 'paid']);
            $hasDepExtra = $depExtra > 0 && !$isFullPaid;
        @endphp
        <div style="margin-top:0.75rem; border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.875rem; overflow:hidden;">
            {{-- Header --}}
            <div style="background:var(--boulder-95, #F9FAFB); padding:0.5rem 0.75rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--boulder-80, #E5E7EB);">
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <x-heroicon-o-banknotes style="width:0.875rem; height:0.875rem; color:var(--boulder-50, #737882);" />
                    <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--boulder-30, #363B45);">Thông tin cọc</span>
                </div>
                @if($isFullPaid)
                    <span style="font-size:0.7rem; font-weight:600; background:#d1fae5; color:#065f46; padding:0.15rem 0.5rem; border-radius:9999px;">✓ Đã thanh toán đủ</span>
                @elseif($isDepPaid)
                    <span style="font-size:0.7rem; font-weight:600; background:#d1fae5; color:#065f46; padding:0.15rem 0.5rem; border-radius:9999px;">✓ Đã nhận cọc</span>
                @else
                    <span style="font-size:0.7rem; font-weight:600; background:var(--boulder-95, #F9FAFB); color:var(--boulder-30, #363B45); border:1px solid var(--boulder-80, #E5E7EB); padding:0.15rem 0.5rem; border-radius:9999px;">⏳ Chờ thanh toán cọc</span>
                @endif
            </div>
            {{-- 3-box grid --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(90px, 1fr)); gap:0.5rem; padding:0.6rem 0.75rem; background:var(--boulder-99, #FBFCFD);">
                <div style="background:var(--boulder-95, #F9FAFB); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.625rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:var(--boulder-50, #737882); margin-bottom:0.2rem;">Tổng tiền phòng</div>
                    <div style="font-size:0.875rem; font-weight:700; color:var(--boulder-20, #272B35);">{{ number_format($realTotal, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:var(--boulder-95, #F9FAFB); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.625rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:var(--boulder-50, #737882); margin-bottom:0.2rem;">Tiền cọc ({{ $depPct }}%)</div>
                    <div style="font-size:0.875rem; font-weight:700; color:var(--boulder-20, #272B35);">{{ number_format($depositAmt, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:{{ $isFullPaid ? '#f0fdf4' : 'var(--boulder-95, #F9FAFB)' }}; border:1px solid {{ $isFullPaid ? '#86efac' : 'var(--boulder-80, #E5E7EB)' }}; border-radius:0.625rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:var(--boulder-50, #737882); margin-bottom:0.2rem;">Còn lại khi nhận phòng</div>
                    @if($isFullPaid)
                        <div style="font-size:0.875rem; font-weight:700; color:#15803d;">Đã trả đủ</div>
                    @else
                        <div style="font-size:0.875rem; font-weight:700; color:#dc2626;">{{ number_format($remain2, 0, ',', '.') }}đ</div>
                    @endif
                </div>
            </div>
            {{-- Phát sinh thêm (nếu có) --}}
            @if($hasDepExtra)
            <div style="padding:0.4rem 0.75rem; background:var(--boulder-95, #F9FAFB); border-top:1px solid var(--boulder-80, #E5E7EB); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.7rem; color:var(--boulder-30, #363B45); display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-plus-circle style="width:0.75rem; height:0.75rem; flex-shrink:0; color:var(--boulder-50, #737882);" />
                    Phát sinh thêm (cộng vào lần thanh toán còn lại)
                </span>
                <span style="font-size:0.75rem; font-weight:700; color:var(--boulder-20, #272B35); white-space:nowrap; margin-left:0.5rem;">
                    +{{ number_format($depExtra, 0, ',', '.') }}đ
                </span>
            </div>
            <div style="padding:0.35rem 0.75rem; background:var(--boulder-95, #F9FAFB); display:flex; justify-content:space-between; align-items:center; border-top:1px dashed var(--boulder-80, #E5E7EB);">
                <span style="font-size:0.7rem; color:var(--boulder-50, #737882);">
                    {{ number_format($baseRemain, 0, ',', '.') }}đ (gốc) + {{ number_format($depExtra, 0, ',', '.') }}đ (phát sinh)
                </span>
                <span style="font-size:0.8rem; font-weight:700; color:#dc2626; white-space:nowrap; margin-left:0.5rem;">
                    = {{ number_format($remain2, 0, ',', '.') }}đ
                </span>
            </div>
            @endif
            {{-- Note --}}
            <div style="padding:0.4rem 0.75rem; background:var(--boulder-99, #FBFCFD); border-top:1px solid var(--boulder-80, #E5E7EB);">
                @if($isFullPaid)
                    <span style="font-size:0.7rem; color:#15803d; display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-check-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Đã thanh toán đủ — mã cổng đã được gán cho khách.
                    </span>
                @elseif($isDepPaid)
                    <span style="font-size:0.7rem; color:var(--boulder-50, #737882); display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-information-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Đã nhận cọc {{ $depPct }}% — Khách thanh toán {{ number_format($remain2, 0, ',', '.') }}đ còn lại khi nhận phòng.
                    </span>
                @else
                    <span style="font-size:0.7rem; color:var(--boulder-50, #737882); display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-exclamation-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Mã cổng chỉ được gán sau khi khách thanh toán đủ phần còn lại.
                    </span>
                @endif
            </div>
        </div>
        @endif


        {{-- Ghi chú: tổng từ DB hay ước tính --}}
        @if(!$isStyle2)
        @if($useActualDiscount)
        <div style="margin-top:0.5rem; background:var(--boulder-95, #F9FAFB); border:1px solid var(--boulder-80, #E5E7EB); border-radius:0.5rem; padding:0.4rem 0.6rem; display:flex; align-items:flex-start; gap:0.35rem;">
            <x-heroicon-o-check-circle style="width:0.875rem; height:0.875rem; color:var(--boulder-50, #737882); flex-shrink:0; margin-top:0.05rem;" />
            <span style="font-size:0.7rem; color:var(--boulder-50, #737882); line-height:1.4;">
                Tổng tính từ dữ liệu thực tế của đơn hàng (bao gồm KM và chiết khấu đã áp dụng).
            </span>
        </div>
        @endif
        @endif

    @endif
</div>
