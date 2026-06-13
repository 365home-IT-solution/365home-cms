@php
    $slotCount      = is_array($items) ? count($items) : 0;
    $guestCountUsed = (int)($guestCount ?? 1); // Order-level guest count (passed from Placeholder)

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

        // Phụ thu khách: slot → × 1, daily → × số đêm (matching API)
        if ($feeEach > 0 && $guestCountUsed > $maxFree) {
            $extraGuests      = $guestCountUsed - $maxFree;
            $surchargeNights  = $isStyle2 ? $groupSlotCount : 1;
            $surcharge        = $extraGuests * $feeEach * $surchargeNights;
            $totalGuestSurcharge += $surcharge;
            $guestSurchargeDetails[] = [
                'extra_guests' => $extraGuests,
                'max_free'     => $maxFree,
                'fee_each'     => $feeEach,
                'nights'       => $surchargeNights,
                'total'        => $surcharge,
            ];
        }
    }

    foreach ($noProductItems as $item) {
        $originalTotal += (float)($item['price'] ?? 0);
    }

    // Khi edit đơn đã tồn tại: dùng discount thực tế từ DB (bao gồm KM khung giờ)
    // record->amount - record->full_amount = tổng giảm giá trên slot (bất biến dù service thay đổi)
    $hasRecord         = isset($record) && $record && $record->id;
    $actualSlotDiscount = 0;
    $useActualDiscount  = false;
    if ($hasRecord && (int)($record->amount ?? 0) > 0) {
        $actualSlotDiscount = max(0, (int)$record->amount - (int)$record->full_amount);
        if ($actualSlotDiscount > 0) { $useActualDiscount = true; }
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

    $finalTotal = $totalAfterBulk + $totalGuestSurcharge + $serviceTotal;

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
                    {{ $isStyle2 ? 'Phòng thuê' : 'Khung giờ' }} ({{ $slotCount }})
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

                    {{-- Số khách (hiển thị order-level guest count) --}}
                    @if(!$isStyle2 && $guestCountUsed > 0)
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.35rem; padding-top:0.35rem; border-top:1px dashed #e2e8f0;">
                        <span style="font-size:0.7rem; color:#64748b; display:flex; align-items:center; gap:0.3rem;">
                            <x-heroicon-o-users style="width:0.75rem; height:0.75rem;" />
                            {{ $guestCountUsed }} khách{{ $guestCountUsed > $maxFreeItem ? ' (phụ thu ' . ($guestCountUsed - $maxFreeItem) . ' người)' : '' }}
                        </span>
                    </div>
                    @endif
                </div>
                @endif
            @endforeach
        </div>

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
                    Phụ thu {{ $detail['extra_guests'] }} người (trên {{ $detail['max_free'] }} miễn phí){{ ($detail['nights'] ?? 1) > 1 ? ' × ' . $detail['nights'] . ' đêm' : '' }}
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

        {{-- ===== THÔNG TIN CỌC (chỉ hiện với style=2 khi có cọc) ===== --}}
        @if(isset($record) && $record && $record->deposit_percent !== null && $isStyle2)
        @php
            $depPct     = (int) $record->deposit_percent;
            $fullAmt2   = (int) ($record->full_amount ?? $record->amount);
            $paidAmt2   = (int) $record->amount;
            $remain2    = max(0, $fullAmt2 - $paidAmt2);
            $isFullPaid = $record->status === 'paid';
            $isDepPaid  = in_array($record->status, ['deposit', 'paid']);
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
                    <div style="font-size:0.875rem; font-weight:700; color:#15803d;">{{ number_format($fullAmt2, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:#fffbeb; border:1px solid #fbbf24; border-radius:0.5rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:#6b7280; margin-bottom:0.2rem;">Tiền cọc ({{ $depPct }}%)</div>
                    <div style="font-size:0.875rem; font-weight:700; color:#d97706;">{{ number_format($paidAmt2, 0, ',', '.') }}đ</div>
                </div>
                <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:0.5rem; padding:0.5rem 0.6rem; text-align:center;">
                    <div style="font-size:0.65rem; color:#6b7280; margin-bottom:0.2rem;">Còn lại khi nhận phòng</div>
                    <div style="font-size:0.875rem; font-weight:700; color:#dc2626;">{{ number_format($remain2, 0, ',', '.') }}đ</div>
                </div>
            </div>
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
        @if(!$isStyle2 && $slotCount >= 2 && count($allDiscountRulesSummary) > 0)
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
        @if($useActualDiscount)
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
