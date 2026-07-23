@php
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

            $timeslotDetails[] = [
                'room'       => $item['name'] ?? '',
                'start'      => $start,
                'end'        => $end,
                'over_night' => $start->format('Y-m-d') !== $end->format('Y-m-d'),
                'price'      => (float) ($item['price'] ?? 0),
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

    // Tính tổng áp dụng bulk_discount_rules và phụ thu khách (order-level, 1 lần)
    $originalTotal       = 0;
    $totalBulkDiscount   = 0;
    $totalGuestSurcharge = 0;
    $bulkDiscountDetails   = [];
    $guestSurchargeDetails = [];

    foreach ($itemsByProduct as $pid => $groupItems) {
        $prod    = $productCache[$pid] ?? null;
        $cfg     = $prod ? ($prod->room_config ?? []) : [];
        $maxFree = (int)($cfg['max_free_guests'] ?? 2);
        $feeEach = (int)($cfg['extra_guest_fee'] ?? 0);

        // Mỗi khung giờ = 1 dòng order_item — đếm theo SỐ DÒNG của cùng 1 phòng để tính bậc giảm
        // giá "đặt nhiều khung giờ" (giống đúng cách client/API tạo dữ liệu).
        $groupSlotCount  = count($groupItems);
        $bulkDiscountPct = 0;

        if ($prod && $groupSlotCount >= 2) {
            $rules = $prod->bulk_discount_rules ?? [];
            usort($rules, fn($a, $b) => (int)($b['slots'] ?? 0) - (int)($a['slots'] ?? 0));
            foreach ($rules as $rule) {
                if ($groupSlotCount >= (int)($rule['slots'] ?? 0)) {
                    $bulkDiscountPct = (float)($rule['discount'] ?? 0);
                    break;
                }
            }
        }

        $groupOriginal = 0;
        foreach ($groupItems as $item) {
            $groupOriginal += (float)($item['price'] ?? 0);
        }
        $originalTotal += $groupOriginal;

        if ($bulkDiscountPct > 0) {
            $groupDiscount = round($groupOriginal * $bulkDiscountPct / 100);
            $totalBulkDiscount += $groupDiscount;
            $bulkDiscountDetails[] = [
                'slots'  => $groupSlotCount,
                'pct'    => $bulkDiscountPct,
                'amount' => $groupDiscount,
            ];
        }

    }

    // Phụ thu khách tính 1 LẦN cho mỗi LƯỢT ĐẶT PHÒNG (1 dòng Repeater gốc), KHÔNG nhân theo số
    // khung giờ đã chọn trong lượt đặt đó — vì vậy phải tính trên $originalItems (dòng gốc, TRƯỚC
    // khi tách thành nhiều khung giờ ở $items), không phải $groupItems (đã bị tách theo khung giờ).
    //
    // Style 2 ("đặt theo ngày") là NGOẠI LỆ — phòng ở NHIỀU ĐÊM thì phụ thu khách phải nhân theo
    // SỐ ĐÊM (khớp OrderForm::calculateGuestSurcharge() / ExtraChargeService::calcGuestSurcharge()
    // / BookingController::buildGuestSurcharge() phía client) — trước đây card này chỉ tính 1 lần
    // duy nhất bất kể số đêm, thấp hơn số khách thấy khi tự đặt qua web.
    $rawItems = (isset($originalItems) && is_array($originalItems)) ? $originalItems : ($items ?? []);
    foreach ($rawItems as $item) {
        $pid = $item['product_id'] ?? null;
        if (! $pid) {
            continue;
        }

        $prod    = $productCache[$pid] ?? null;
        $cfg     = $prod ? ($prod->room_config ?? []) : [];
        $maxFree = (int) ($cfg['max_free_guests'] ?? 2);
        $feeEach = (int) ($cfg['extra_guest_fee'] ?? 0);

        $itemGuestCount = (int) ($item['guest_count'] ?? 1);

        if ($feeEach > 0 && $itemGuestCount > $maxFree) {
            $extraGuests = $itemGuestCount - $maxFree;

            $itemNights = 1;
            $itemStyle  = (int) ($item['product_style'] ?? ($prod->styles ?? 1));
            if ($itemStyle === 2) {
                $itemCheckin  = $item['checkin_day'] ?? $item['checkin_date'] ?? null;
                $itemCheckout = $item['checkout_day'] ?? $item['checkout_date'] ?? null;
                if ($itemCheckin && $itemCheckout) {
                    $itemNights = max(1, (int) \Carbon\Carbon::parse($itemCheckin)->startOfDay()
                        ->diffInDays(\Carbon\Carbon::parse($itemCheckout)->startOfDay()));
                }
            }

            $surcharge   = $extraGuests * $feeEach * $itemNights;
            $totalGuestSurcharge += $surcharge;
            $guestSurchargeDetails[] = [
                'room'         => $item['name'] ?? '',
                'extra_guests' => $extraGuests,
                'max_free'     => $maxFree,
                'fee_each'     => $feeEach,
                'nights'       => $itemNights,
                'total'        => $surcharge,
            ];
        }
    }

    foreach ($noProductItems as $item) {
        $originalTotal += (float)($item['price'] ?? 0);
    }

    // Khi edit đơn CỌC đã tồn tại: dùng discount thực tế từ DB (bao gồm KM khung giờ)
    // record->amount - reconstructed_real_total = tổng giảm giá trên slot (bất biến dù service
    // thay đổi). full_amount CHỈ có ý nghĩa với đơn CỌC (là số tiền đã cọc, xem field "Số tiền đã
    // cọc" ở Section "Thanh toán còn lại") — đơn thanh toán đủ (deposit_percent = null) KHÔNG BAO
    // GIỜ set full_amount (luôn rỗng), nên KHÔNG được dùng full_amount để suy discount cho đơn
    // loại này (trước đây coi full_amount rỗng = 0 khiến discount = gần như toàn bộ amount, sai
    // hoàn toàn) — đơn thường luôn dùng discount tính LIVE từ bulk_discount_rules.
    $hasRecord         = isset($record) && $record && $record->id;
    $actualSlotDiscount = 0;
    $useActualDiscount  = false;
    if ($hasRecord && (int)($record->amount ?? 0) > 0) {
        $recordDepositPct = $record->deposit_percent !== null ? (int)$record->deposit_percent : null;
        if ($recordDepositPct !== null && $recordDepositPct > 0 && $recordDepositPct < 100 && (int)($record->full_amount ?? 0) > 0) {
            // Đơn cọc: full_amount = tiền cọc, phải reconstruct tổng thực trước khi tính discount
            $recordRealTotal    = (int) round((int)$record->full_amount * 100 / $recordDepositPct);
            $actualSlotDiscount = max(0, (int)$record->amount - $recordRealTotal);
            if ($actualSlotDiscount > 0) { $useActualDiscount = true; }
        }
    }

    $effectiveDiscount = $useActualDiscount ? $actualSlotDiscount : $totalBulkDiscount;
    $totalAfterBulk    = max(0, $originalTotal - $effectiveDiscount);
    $hasDiscount       = $effectiveDiscount > 0;

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

    $computedLiveTotal = $totalAfterBulk + $totalGuestSurcharge + $serviceTotal;
    $finalTotal = $computedLiveTotal;

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

    // Thu thập tất cả mức giảm giá duy nhất để hiển thị ghi chú
    $allDiscountRulesSummary = [];
    if (!$isStyle2) {
        foreach ($productCache as $prod) {
            foreach ($prod->bulk_discount_rules ?? [] as $rule) {
                $key = (int)($rule['slots'] ?? 0);
                if ($key >= 2 && !isset($allDiscountRulesSummary[$key])) {
                    $allDiscountRulesSummary[$key] = (float)($rule['discount'] ?? 0);
                }
            }
        }
        ksort($allDiscountRulesSummary);
    }
@endphp

<div style="font-family: inherit;">

    @if($slotCount === 0)
        {{-- Empty state --}}
        <div style="text-align:center; padding: 2rem 1rem;">
            <div style="font-size:2.5rem; margin-bottom:0.5rem;">🏠</div>
            <div style="font-size:1.5rem; font-weight:700; color:#d1d5db; margin-bottom:0.25rem;">0 VNĐ</div>
            <p style="color:#9ca3af; font-size:0.8125rem;">Chưa có phòng nào được chọn</p>
        </div>
    @else

        {{-- ===== PHÒNG ===== --}}
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-home style="width:1rem; height:1rem; color:#6b7280;" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280;">
                    {{ $isStyle2 ? 'Phòng thuê' : 'Khung giờ' }} ({{ $isStyle2 ? $slotCount : $totalSlotCount }})
                </span>
            </div>

            @foreach($items as $item)
                @if(isset($item['name'], $item['price']))
                @php
                    $itemStyle   = (int)($item['product_style'] ?? 1);
                    $itemPid     = $item['product_id'] ?? null;
                    $itemProd    = $itemPid ? ($productCache[$itemPid] ?? null) : null;
                    $itemCfg     = $itemProd ? ($itemProd->room_config ?? []) : [];
                    $maxFreeItem = (int)($itemCfg['max_free_guests'] ?? 2);
                @endphp
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:0.5rem; padding:0.6rem 0.75rem; margin-bottom:0.4rem;">
                    {{-- Room name + price --}}
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem;">
                        <div style="display:flex; align-items:center; gap:0.4rem; min-width:0;">
                            <x-heroicon-s-home style="width:0.875rem; height:0.875rem; color:#3b82f6; flex-shrink:0;" />
                            <span style="font-weight:600; font-size:0.8125rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                {{ $item['name'] }}
                            </span>
                        </div>
                        <span style="font-weight:700; font-size:0.8125rem; color:#1e40af; white-space:nowrap; flex-shrink:0;">
                            {{ number_format((float)$item['price'], 0, ',', '.') }} đ
                        </span>
                    </div>

                    {{-- Style 2: ngày nhận/trả + số đêm --}}
                    @if($itemStyle === 2 && !empty($item['checkin_date']) && !empty($item['checkout_date']))
                        @php
                            $ci = \Carbon\Carbon::parse($item['checkin_date']);
                            $co = \Carbon\Carbon::parse($item['checkout_date']);
                            $nights = max(0, $ci->diffInDays($co));
                            $ppn    = (float)($item['price_per_night'] ?? 0);
                        @endphp
                        @if($nights > 0)
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px dashed #e2e8f0;">
                            <span style="font-size:0.7rem; color:#64748b; display:flex; align-items:center; gap:0.3rem;">
                                <x-heroicon-o-calendar-days style="width:0.75rem; height:0.75rem;" />
                                {{ $ci->format('d/m') }} → {{ $co->format('d/m/Y') }}
                            </span>
                            <span style="font-size:0.7rem; color:#64748b; font-weight:500; white-space:nowrap;">
                                {{ $nights }} đêm{{ $ppn > 0 ? ' × ' . number_format($ppn, 0, ',', '.') . 'đ' : '' }}
                            </span>
                        </div>
                        @endif
                    @endif

                    {{-- Style 1: giờ check-in/out --}}
                    @if($itemStyle !== 2 && !empty($item['checkin_date']) && !empty($item['checkout_date']))
                        @php
                            $ci2 = \Carbon\Carbon::parse($item['checkin_date']);
                            $co2 = \Carbon\Carbon::parse($item['checkout_date']);
                        @endphp
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px dashed #e2e8f0;">
                            <span style="font-size:0.7rem; color:#64748b; display:flex; align-items:center; gap:0.3rem;">
                                <x-heroicon-o-clock style="width:0.75rem; height:0.75rem;" />
                                {{ $ci2->format('d/m H:i') }}
                            </span>
                            <span style="font-size:0.7rem; color:#64748b; display:flex; align-items:center; gap:0.3rem;">
                                <x-heroicon-o-arrow-right style="width:0.75rem; height:0.75rem;" />
                                {{ $co2->format('d/m H:i') }}
                            </span>
                        </div>
                    @endif

                    {{-- Số khách CỦA RIÊNG PHÒNG NÀY — mỗi phòng phụ thu độc lập theo số khách của
                         chính nó, không dùng chung 1 số khách cho cả đơn. --}}
                    @php $itemGuestCountDisplay = (int) ($item['guest_count'] ?? 1); @endphp
                    @if(!$isStyle2 && $itemGuestCountDisplay > 0)
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px dashed #e2e8f0;">
                        <span style="font-size:0.7rem; color:#64748b; display:flex; align-items:center; gap:0.3rem;">
                            <x-heroicon-o-users style="width:0.75rem; height:0.75rem;" />
                            {{ $itemGuestCountDisplay }} khách{{ $itemGuestCountDisplay > $maxFreeItem ? ' (phụ thu ' . ($itemGuestCountDisplay - $maxFreeItem) . ' người)' : '' }}
                        </span>
                    </div>
                    @endif
                </div>
                @endif
            @endforeach
        </div>

        {{-- ===== CHI TIẾT TỪNG KHUNG GIỜ (thẻ collapse) ===== --}}
        @if(!$isStyle2 && count($timeslotDetails) > 0)
        <details style="margin-bottom:0.75rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:0.5rem;">
            <summary style="cursor:pointer; padding:0.5rem 0.75rem; font-size:0.75rem; font-weight:600; color:#374151; display:flex; align-items:center; gap:0.35rem; list-style:none;">
                <x-heroicon-o-clock style="width:0.875rem; height:0.875rem; color:#6b7280;" />
                Xem chi tiết từng khung giờ ({{ count($timeslotDetails) }})
            </summary>
            <div style="padding:0 0.75rem 0.6rem;">
                @foreach($timeslotDetails as $detail)
                <div style="display:flex; justify-content:space-between; align-items:center; padding:0.3rem 0;{{ !$loop->last ? ' border-bottom:1px dashed #e2e8f0;' : '' }}">
                    <span style="font-size:0.75rem; color:#374151;">
                        <span style="font-weight:600;">{{ $detail['start']->format('H:i') }}–{{ $detail['end']->format('H:i') }}</span>
                        <span style="color:#6b7280;">{{ $detail['start']->format('d/m/Y') }}{{ $detail['over_night'] ? ' (qua đêm)' : '' }}</span>
                        @if(count($itemsByProduct) > 1 || $detail['room'])
                            <span style="color:#9ca3af;">— {{ $detail['room'] }}</span>
                        @endif
                    </span>
                    <span style="font-size:0.75rem; font-weight:600; color:#1e40af; white-space:nowrap;">
                        {{ number_format($detail['price'], 0, ',', '.') }} đ
                    </span>
                </div>
                @endforeach
            </div>
        </details>
        @endif

        {{-- ===== DỊCH VỤ ===== --}}
        @if($serviceItems->count() > 0)
        <div style="margin-bottom:0.75rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;">
                <x-heroicon-o-shopping-bag style="width:1rem; height:1rem; color:#6b7280;" />
                <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280;">
                    Dịch vụ thêm ({{ $serviceItems->count() }})
                </span>
            </div>

            <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:0.5rem; padding:0.5rem 0.75rem;">
                @foreach($serviceItems as $svc)
                <div style="display:flex; justify-content:space-between; align-items:center; padding:0.3rem 0;{{ !$loop->last ? ' border-bottom:1px dashed #fed7aa;' : '' }}">
                    <div style="display:flex; align-items:center; gap:0.35rem; min-width:0;">
                        <x-heroicon-o-check-circle style="width:0.75rem; height:0.75rem; color:#ea580c; flex-shrink:0;" />
                        <span style="font-size:0.75rem; color:#7c2d12; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            {{ $svc->service_name }}
                        </span>
                        <span style="font-size:0.7rem; color:#9a3412; background:#fed7aa; border-radius:9999px; padding:0 0.35rem; white-space:nowrap; flex-shrink:0;">
                            ×{{ $svc->quantity }}
                        </span>
                    </div>
                    <span style="font-size:0.75rem; font-weight:600; color:#c2410c; white-space:nowrap; flex-shrink:0; margin-left:0.5rem;">
                        +{{ number_format((float)$svc->subtotal, 0, ',', '.') }} đ
                    </span>
                </div>
                @endforeach

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px solid #fdba74;">
                    <span style="font-size:0.75rem; font-weight:600; color:#9a3412;">Tổng dịch vụ</span>
                    <span style="font-size:0.8125rem; font-weight:700; color:#c2410c;">
                        +{{ number_format($serviceTotal, 0, ',', '.') }} đ
                    </span>
                </div>
            </div>
        </div>
        @endif

        {{-- ===== BREAKDOWN ===== --}}
        <div style="border-top:1px solid #e2e8f0; padding-top:0.6rem; margin-bottom:0.6rem;">
            @if($isPaidWithExtra)
            {{-- Đơn paid có phát sinh: hiển thị 2 dòng rõ ràng thay vì reconstruct --}}
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#6b7280; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-lock-closed style="width:0.75rem; height:0.75rem;" />
                    Đã thanh toán
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:#374151;">
                    {{ number_format((int)$record->full_amount, 0, ',', '.') }} đ
                </span>
            </div>
            @php
                $extraIsPaid = !is_null($record->extra_charge_paid_at);
                $extraMethod = $record->extra_charge_payment_method ?? null;
                $extraLabel  = $extraIsPaid
                    ? ('Phát sinh — đã thu' . match ($extraMethod) {
                        'cod' => ' (tiền mặt)',
                        'bank_transfer' => ' (chuyển khoản)',
                        default => ' (QR)',
                    })
                    : 'Phát sinh — chưa thanh toán';
                $extraColor  = $extraIsPaid ? '#15803d' : '#dc2626';
            @endphp
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:{{ $extraColor }}; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-plus-circle style="width:0.75rem; height:0.75rem;" />
                    {{ $extraLabel }}
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:{{ $extraColor }};">
                    +{{ number_format($extraChargeAmt, 0, ',', '.') }} đ
                </span>
            </div>
            @else
            @if($hasDiscount && !$isStyle2)
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#6b7280;">Giá phòng gốc</span>
                <span style="font-size:0.75rem; color:#9ca3af; text-decoration:line-through;">
                    {{ number_format($originalTotal, 0, ',', '.') }} đ
                </span>
            </div>
            @if($useActualDiscount)
            <div style="display:flex; justify-content:space-between; align-items:center; background:#eff6ff; border-radius:0.375rem; padding:0.3rem 0.5rem; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#1d4ed8; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-receipt-percent style="width:0.75rem; height:0.75rem;" />
                    Giảm (KM + chiết khấu thực tế)
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:#1d4ed8;">
                    -{{ number_format($effectiveDiscount, 0, ',', '.') }} đ
                </span>
            </div>
            @else
            @foreach($bulkDiscountDetails as $detail)
            <div style="display:flex; justify-content:space-between; align-items:center; background:#eff6ff; border-radius:0.375rem; padding:0.3rem 0.5rem; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#1d4ed8; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-receipt-percent style="width:0.75rem; height:0.75rem;" />
                    Giảm {{ $detail['slots'] }} khung ({{ $detail['pct'] }}%)
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:#1d4ed8;">
                    -{{ number_format($detail['amount'], 0, ',', '.') }} đ
                </span>
            </div>
            @endforeach
            @endif
            @endif

            @if($totalGuestSurcharge > 0)
            @foreach($guestSurchargeDetails as $detail)
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#6b7280; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-user-plus style="width:0.75rem; height:0.75rem;" />
                    Phụ thu {{ $detail['extra_guests'] }} người (trên {{ $detail['max_free'] }} miễn phí){{ ($detail['nights'] ?? 1) > 1 ? ' × ' . $detail['nights'] . ' đêm' : '' }}{{ !empty($detail['room']) ? ' — ' . $detail['room'] : '' }}
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:#ea580c;">
                    +{{ number_format($detail['total'], 0, ',', '.') }} đ
                </span>
            </div>
            @endforeach
            @endif

            @if($serviceTotal > 0)
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                <span style="font-size:0.75rem; color:#6b7280; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-shopping-bag style="width:0.75rem; height:0.75rem;" />
                    Dịch vụ ({{ $serviceItems->count() }} loại)
                </span>
                <span style="font-size:0.75rem; font-weight:600; color:#ea580c;">
                    +{{ number_format($serviceTotal, 0, ',', '.') }} đ
                </span>
            </div>
            @endif
            @endif
        </div>

        {{-- ===== TỔNG CUỐI ===== --}}
        <div style="background:linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius:0.625rem; padding:0.75rem 1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:0.7rem; color:#bfdbfe; font-weight:500; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.15rem;">
                        Tổng thanh toán
                    </div>
                    <div style="font-size:0.7rem; color:#93c5fd;">
                        {{ $slotCount }} phòng{{ $serviceItems->count() > 0 ? ' + ' . $serviceItems->count() . ' dịch vụ' : '' }}
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.375rem; font-weight:800; color:#ffffff; line-height:1.2;">
                        {{ number_format($finalTotal, 0, ',', '.') }}
                    </div>
                    <div style="font-size:0.7rem; color:#bfdbfe; font-weight:600;">VNĐ</div>
                </div>
            </div>
        </div>

        @if($liveDiff !== null)
        {{-- Bấm THẲNG vào dòng chênh lệch này để Lưu đơn ngay — trước đây admin phải tự tìm nút
             "Lưu" ở nơi khác trên trang rồi mới thấy panel "Phát sinh thêm/Hoàn tiền chưa xử lý"
             hiện ra ở tab Thông tin thanh toán, cảm giác dư thừa 1 bước. Bấm ở đây gọi thẳng
             wire:click="save" (action Lưu chuẩn của Filament EditRecord) — sau khi lưu xong,
             EditOrder::handlePriceDiff() tự tạo QR/ghi khoản phát sinh và panel tương ứng hiện
             NGAY trên cùng trang, không cần thao tác gì thêm. --}}
        <button
            type="button"
            wire:key="live-diff-save-btn-{{ $hasRecord ? $record->id : 'new' }}"
            wire:click="save"
            wire:loading.attr="disabled"
            wire:target="save"
            style="display:flex; width:100%; justify-content:space-between; align-items:center; margin-top:0.5rem; padding:0.5rem 0.75rem; border-radius:0.5rem; background:{{ $liveDiff > 0 ? '#fffbeb' : '#f0fdf4' }}; border:1px solid {{ $liveDiff > 0 ? '#fde68a' : '#bbf7d0' }}; cursor:pointer; font-family:inherit; text-align:left;"
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
            $depPct      = (int) $record->deposit_percent;
            $depositAmt  = (int) $record->full_amount;
            $realTotal   = $depPct > 0 ? (int) round($depositAmt * 100 / $depPct) : $depositAmt;
            $depExtra    = (int) ($record->extra_charge_amount ?? 0);
            $baseRemain  = max(0, $realTotal - $depositAmt);
            $remain2     = $baseRemain + $depExtra;
            $isFullPaid  = $record->status === 'paid';
            $isDepPaid   = in_array($record->status, ['deposit', 'paid']);
            $hasDepExtra = $depExtra > 0 && !$isFullPaid;
        @endphp
        <div style="margin-top:0.75rem; border:1px solid #fde68a; border-radius:0.625rem; overflow:hidden;">
            {{-- Header --}}
            <div style="background:#fffbeb; padding:0.5rem 0.75rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #fde68a;">
                <div style="display:flex; align-items:center; gap:0.4rem;">
                    <x-heroicon-o-banknotes style="width:0.875rem; height:0.875rem; color:#d97706;" />
                    <span style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:#92400e;">Thông tin cọc</span>
                </div>
                @if($isFullPaid)
                    <span style="font-size:0.7rem; font-weight:600; background:#d1fae5; color:#065f46; padding:0.15rem 0.5rem; border-radius:9999px;">✓ Đã thanh toán đủ</span>
                @elseif($isDepPaid)
                    <span style="font-size:0.7rem; font-weight:600; background:#d1fae5; color:#065f46; padding:0.15rem 0.5rem; border-radius:9999px;">✓ Đã nhận cọc</span>
                @else
                    <span style="font-size:0.7rem; font-weight:600; background:#fef3c7; color:#92400e; padding:0.15rem 0.5rem; border-radius:9999px;">⏳ Chờ thanh toán cọc</span>
                @endif
            </div>
            {{-- 3-box grid --}}
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5rem; padding:0.6rem 0.75rem; background:#ffffff;">
                <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:0.5rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:#6b7280; margin-bottom:0.2rem;">Tổng tiền phòng</div>
                    <div style="font-size:0.875rem; font-weight:700; color:#15803d;">{{ number_format($realTotal, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:0.5rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:#6b7280; margin-bottom:0.2rem;">Tiền cọc ({{ $depPct }}%)</div>
                    <div style="font-size:0.875rem; font-weight:700; color:#d97706;">{{ number_format($depositAmt, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:{{ $isFullPaid ? '#f0fdf4' : '#fef2f2' }}; border:1px solid {{ $isFullPaid ? '#86efac' : '#fca5a5' }}; border-radius:0.5rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:#6b7280; margin-bottom:0.2rem;">Còn lại khi nhận phòng</div>
                    @if($isFullPaid)
                        <div style="font-size:0.875rem; font-weight:700; color:#15803d;">Đã trả đủ</div>
                    @else
                        <div style="font-size:0.875rem; font-weight:700; color:#dc2626;">{{ number_format($remain2, 0, ',', '.') }}đ</div>
                    @endif
                </div>
            </div>
            {{-- Phát sinh thêm (nếu có) --}}
            @if($hasDepExtra)
            <div style="padding:0.4rem 0.75rem; background:#fff7ed; border-top:1px solid #fde68a; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.7rem; color:#c2410c; display:flex; align-items:center; gap:0.3rem;">
                    <x-heroicon-o-plus-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                    Phát sinh thêm (cộng vào lần thanh toán còn lại)
                </span>
                <span style="font-size:0.75rem; font-weight:700; color:#c2410c; white-space:nowrap; margin-left:0.5rem;">
                    +{{ number_format($depExtra, 0, ',', '.') }}đ
                </span>
            </div>
            <div style="padding:0.35rem 0.75rem; background:#fff7ed; display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #fde68a;">
                <span style="font-size:0.7rem; color:#92400e;">
                    {{ number_format($baseRemain, 0, ',', '.') }}đ (gốc) + {{ number_format($depExtra, 0, ',', '.') }}đ (phát sinh)
                </span>
                <span style="font-size:0.8rem; font-weight:700; color:#dc2626; white-space:nowrap; margin-left:0.5rem;">
                    = {{ number_format($remain2, 0, ',', '.') }}đ
                </span>
            </div>
            @endif
            {{-- Note --}}
            <div style="padding:0.4rem 0.75rem; background:#fffbeb; border-top:1px solid #fde68a;">
                @if($isFullPaid)
                    <span style="font-size:0.7rem; color:#15803d; display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-check-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Đã thanh toán đủ — mã cổng đã được gán cho khách.
                    </span>
                @elseif($isDepPaid)
                    <span style="font-size:0.7rem; color:#92400e; display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-information-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Đã nhận cọc {{ $depPct }}% — Khách thanh toán {{ number_format($remain2, 0, ',', '.') }}đ còn lại khi nhận phòng.
                    </span>
                @else
                    <span style="font-size:0.7rem; color:#92400e; display:flex; align-items:center; gap:0.3rem;">
                        <x-heroicon-o-exclamation-circle style="width:0.75rem; height:0.75rem; flex-shrink:0;" />
                        Mã cổng chỉ được gán sau khi khách thanh toán đủ phần còn lại.
                    </span>
                @endif
            </div>
        </div>
        @endif

        {{-- Ghi chú mức giảm giá từ cài đặt --}}
        @if(!$isStyle2 && $totalSlotCount >= 2 && count($allDiscountRulesSummary) > 0)
        <div style="margin-top:0.5rem; background:#f0f9ff; border:1px solid #bae6fd; border-radius:0.375rem; padding:0.4rem 0.6rem; display:flex; align-items:flex-start; gap:0.35rem;">
            <x-heroicon-o-information-circle style="width:0.875rem; height:0.875rem; color:#0284c7; flex-shrink:0; margin-top:0.05rem;" />
            <span style="font-size:0.7rem; color:#0369a1; line-height:1.4;">
                @foreach($allDiscountRulesSummary as $ruleSlots => $ruleDiscount)
                    Giảm {{ $ruleDiscount }}% khi đặt {{ $ruleSlots }} khung{{ !$loop->last ? ' · ' : '' }}
                @endforeach
            </span>
        </div>
        @endif

        {{-- Ghi chú: tổng từ DB hay ước tính --}}
        @if(!$isStyle2)
        @if($isPaidWithExtra)
        <div style="margin-top:0.5rem; background:#f0fdf4; border:1px solid #86efac; border-radius:0.375rem; padding:0.4rem 0.6rem; display:flex; align-items:flex-start; gap:0.35rem;">
            <x-heroicon-o-check-circle style="width:0.875rem; height:0.875rem; color:#16a34a; flex-shrink:0; margin-top:0.05rem;" />
            <span style="font-size:0.7rem; color:#15803d; line-height:1.4;">
                Tổng = số tiền đã thanh toán + phát sinh thêm. Dịch vụ/phụ thu hiển thị phía trên chỉ mang tính tham khảo.
            </span>
        </div>
        @elseif($useActualDiscount)
        <div style="margin-top:0.5rem; background:#f0fdf4; border:1px solid #86efac; border-radius:0.375rem; padding:0.4rem 0.6rem; display:flex; align-items:flex-start; gap:0.35rem;">
            <x-heroicon-o-check-circle style="width:0.875rem; height:0.875rem; color:#16a34a; flex-shrink:0; margin-top:0.05rem;" />
            <span style="font-size:0.7rem; color:#15803d; line-height:1.4;">
                Tổng tính từ dữ liệu thực tế của đơn hàng (bao gồm KM và chiết khấu đã áp dụng).
            </span>
        </div>
        @else
        <div style="margin-top:0.5rem; background:#fef9c3; border:1px solid #fde047; border-radius:0.375rem; padding:0.4rem 0.6rem; display:flex; align-items:flex-start; gap:0.35rem;">
            <x-heroicon-o-exclamation-triangle style="width:0.875rem; height:0.875rem; color:#ca8a04; flex-shrink:0; margin-top:0.05rem;" />
            <span style="font-size:0.7rem; color:#854d0e; line-height:1.4;">
                Ước tính chưa bao gồm: <strong>khuyến mãi khung giờ</strong> và <strong>coupon</strong>. Giá thực tế có thể thấp hơn khi đặt qua API.
            </span>
        </div>
        @endif
        @endif

    @endif
</div>
