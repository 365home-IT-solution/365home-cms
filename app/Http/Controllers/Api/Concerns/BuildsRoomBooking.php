<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Services\PromotionCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use PayOS\PayOS;

/**
 * Engine tính giá/khung giờ dùng chung cho mọi luồng tạo đơn (khách đăng nhập,
 * khách vãng lai, admin đặt hộ) — tách ra từ BookingController/GuestBookingController
 * (2 nơi trước đây đã trùng lặp gần như y hệt) để AdminBookingController không phải
 * copy lần thứ 3. Các phương thức ở đây thuần tính toán (nhận Request/Product làm
 * tham số, không đọc state riêng của controller) nên an toàn để tái sử dụng.
 */
trait BuildsRoomBooking
{
    // ── Slot (nhiều khung giờ) ────────────────────────────────────────────────

    // $excludeOrderId: bỏ qua chính đơn này khi kiểm tra trùng khung giờ — dùng cho preview() sửa
    // đơn (dry-run, KHÔNG xoá order_items cũ trước như update() thật nên phải tự loại trừ, tránh
    // báo trùng với chính lịch hiện tại của đơn đang xem). null (mặc định, các luồng tạo/sửa đơn
    // thật) => không loại trừ gì, giữ nguyên hành vi cũ.
    private function buildSlotItems(Request $request, Product $room, ?int $excludeOrderId = null): array
    {
        $slots         = $request->input('slots');
        $defaultDate   = $request->input('date');
        $totalPrice    = 0;
        $itemsData     = [];
        $slotSummary   = [];
        $rtsCollection = collect();
        $errors        = [];

        foreach ($slots as $index => $slot) {
            $timeslotId = (int) $slot['timeslot_id'];
            $dateStr    = $slot['date'] ?? $defaultDate;

            if (! $dateStr) {
                $errors["slots.{$index}.date"] = ['Vui lòng cung cấp ngày đặt phòng.'];
                continue;
            }

            $rts = $room->roomTimeSlots
                ->filter(fn ($s) => is_null($s->date))
                ->where('timeslot_id', $timeslotId)
                ->first();

            if (! $rts || ! $rts->timeSlot) {
                $errors["slots.{$index}.timeslot_id"] = ['Khung giờ không tồn tại cho phòng này.'];
                continue;
            }

            if ($rts->isBlockedOn($dateStr)) {
                $errors["slots.{$index}.date"] = ['Khung giờ này đã bị chặn vào ngày bạn chọn.'];
                continue;
            }

            $timeSlot = $rts->timeSlot;
            $checkin  = Carbon::parse("{$dateStr} {$timeSlot->start_time}");
            $checkout = Carbon::parse("{$dateStr} {$timeSlot->end_time}");
            if ($checkout->lte($checkin)) {
                $checkout->addDay();
            }

            $conflict = OrderItem::where('product_id', $room->id)
                ->whereNotNull('checkin_date')
                ->whereNotNull('checkout_date')
                ->where('checkin_date', '<', $checkout)
                ->where('checkout_date', '>', $checkin)
                ->when($excludeOrderId, fn ($q) => $q->where('order_id', '!=', $excludeOrderId))
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
                ->exists();

            if ($conflict) {
                $errors["slots.{$index}.timeslot_id"] = ['Khung giờ này đã được đặt rồi.'];
                continue;
            }

            $startLabel  = substr($timeSlot->start_time, 0, 5);
            $endLabel    = substr($timeSlot->end_time, 0, 5);
            $isOvernight = (bool) $rts->over_night;
            $label       = $startLabel . ' - ' . $endLabel . ($isOvernight ? ' (Qua đêm)' : '');
            $price       = (int) $rts->price;

            $totalPrice  += $price;
            $itemsData[]  = [
                'product_id'    => $room->id,
                'name'          => $room->name . ' - ' . $label,
                'price'         => $price,
                'quantity'      => 1,
                'is_shipped'    => true,
                'checkin_date'  => $checkin,
                'checkout_date' => $checkout,
                'extra_fee'     => 0,
                'guest_count'   => $request->guest_count,
                'over_night'    => $isOvernight,
            ];

            $slotSummary[] = [
                'timeslot_id' => $timeslotId,
                'date'        => $dateStr,
                'label'       => $label,
                'price'       => $price,
            ];

            $rtsCollection->push($rts);
        }

        // Gom lỗi của TẤT CẢ khung giờ bị trùng/không hợp lệ trong 1 lần request — xem cùng comment
        // ở BookingController::buildSlotItems() (bản khách đăng nhập, cùng logic).
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $slotCount   = count($slots);
        $summaryName = $slotCount === 1
            ? $itemsData[0]['name']
            : $room->name . ' - ' . $slotCount . ' khung giờ';

        return [$totalPrice, $summaryName, $itemsData, $rtsCollection, $slotSummary];
    }

    // ── Daily (phòng theo ngày) ───────────────────────────────────────────────

    private function buildDailyItems(Request $request, Product $room, ?int $excludeOrderId = null): array
    {
        $checkin  = Carbon::parse($request->checkin_date)->startOfDay();
        $checkout = Carbon::parse($request->checkout_date)->startOfDay();
        $nights   = (int) $checkin->diffInDays($checkout);

        if ($nights < 1) {
            throw ValidationException::withMessages([
                'checkout_date' => ['Phải đặt tối thiểu 1 đêm.'],
            ]);
        }

        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->when($excludeOrderId, fn ($q) => $q->where('order_id', '!=', $excludeOrderId))
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped', 'confirmed']))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'checkin_date' => ['Phòng đã được đặt trong khoảng thời gian này.'],
            ]);
        }

        $slotsByDate = $room->roomTimeSlots
            ->filter(fn ($rts) => $rts->timeSlot?->type === 'date')
            ->keyBy(fn ($rts) => $rts->timeSlot?->label);

        $basePrice   = (float) $room->price;
        $defCheckin  = $room->default_checkin  ?? '14:00';
        $defCheckout = $room->default_checkout ?? '12:00';

        $totalPrice    = 0;
        $itemsData     = [];
        $nightSummary  = [];
        $rtsCollection = collect();

        $current = $checkin->copy();
        while ($current->lt($checkout)) {
            $dateStr     = $current->format('Y-m-d');
            $rts         = $slotsByDate->get($dateStr);
            $nightPrice  = $rts?->price !== null ? (float) $rts->price : $basePrice;
            $checkinTime = $rts?->checkin  ?? $defCheckin;
            $chkoutTime  = $rts?->checkout ?? $defCheckout;
            $nextDate    = $current->copy()->addDay()->format('Y-m-d');
            $checkinDt   = Carbon::parse("{$dateStr} {$checkinTime}");
            $checkoutDt  = Carbon::parse("{$nextDate} {$chkoutTime}");

            $totalPrice += $nightPrice;

            $itemsData[] = [
                'product_id'    => $room->id,
                'name'          => $room->name . ' - ' . $current->format('d/m/Y'),
                'price'         => (int) $nightPrice,
                'quantity'      => 1,
                'is_shipped'    => true,
                'checkin_date'  => $checkinDt,
                'checkout_date' => $checkoutDt,
                'extra_fee'     => 0,
                'guest_count'   => $request->guest_count,
                'over_night'    => true,
            ];

            $nightSummary[] = [
                'date'  => $dateStr,
                'price' => (int) $nightPrice,
            ];

            if ($rts) $rtsCollection->push($rts);
            $current->addDay();
        }

        $summaryName = $room->name . ' - ' . $nights . ' đêm ('
            . $checkin->format('d/m') . ' → ' . $checkout->format('d/m/Y') . ')';

        return [(int) $totalPrice, $summaryName, $itemsData, $rtsCollection, $nightSummary];
    }

    // ── Monthly (thuê tháng) ─────────────────────────────────────────────────

    private function buildMonthlyItem(Request $request, Product $room, ?int $excludeOrderId = null): array
    {
        $checkin  = Carbon::parse($request->checkin_date);
        $checkout = Carbon::parse($request->checkout_date);

        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->when($excludeOrderId, fn ($q) => $q->where('order_id', '!=', $excludeOrderId))
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'checkin_date' => ['Phòng đã được đặt trong khoảng thời gian này.'],
            ]);
        }

        $months   = max(1, (int) $checkin->diffInMonths($checkout));
        $price    = (int) ($room->price * $months);
        $itemName = $room->name . ' - Thuê tháng (' . $months . ' tháng)';

        return [$price, $itemName, [[
            'product_id'    => $room->id,
            'name'          => $itemName,
            'price'         => $price,
            'quantity'      => 1,
            'is_shipped'    => true,
            'checkin_date'  => $checkin,
            'checkout_date' => $checkout,
            'extra_fee'     => 0,
            'guest_count'   => $request->guest_count,
        ]]];
    }

    // ── Additional services ───────────────────────────────────────────────────

    private function buildServices(Request $request, Product $room): array
    {
        $requested = collect($request->input('services', []));
        if ($requested->isEmpty()) {
            return [0, []];
        }

        $availableServices = $room->additionalServices->keyBy('id');
        $total = 0;
        $data  = [];

        foreach ($requested as $index => $entry) {
            $serviceId = (int) $entry['service_id'];
            $quantity  = (int) $entry['quantity'];
            $service   = $availableServices->get($serviceId);

            if (! $service || ! $service->is_active) {
                throw ValidationException::withMessages([
                    "services.{$index}.service_id" => ["Dịch vụ #{$serviceId} không tồn tại hoặc không khả dụng cho phòng này."],
                ]);
            }

            $subtotal = $service->price * $quantity;
            $total   += $subtotal;
            $data[]   = [
                'service_id'   => $service->id,
                'service_name' => $service->name,
                'price'        => (int) $service->price,
                'quantity'     => $quantity,
                'subtotal'     => (int) $subtotal,
            ];
        }

        return [$total, $data];
    }

    // ── Full booking check + discount ─────────────────────────────────────────

    private function checkFullDayBooking(array $slotSummary, Product $room): bool
    {
        if (empty($room->full_booking_discount)) {
            return false;
        }

        $totalSlots = $room->roomTimeSlots
            ->filter(fn ($s) => is_null($s->date))
            ->count();

        if ($totalSlots === 0) {
            return false;
        }

        $slotsByDate = collect($slotSummary)->groupBy('date');
        foreach ($slotsByDate as $dateSlots) {
            if ($dateSlots->count() === $totalSlots) {
                return true;
            }
        }

        return false;
    }

    private function applyFullBookingDiscount(float $amount, Product $room): array
    {
        $rule     = $room->full_booking_discount;
        $discount = (int) $this->parseDiscountRule($amount, $rule);

        return [$discount, [
            'type'            => 'full_booking',
            'label'           => 'Đặt cả ngày',
            'rule'            => $rule,
            'discount_amount' => $discount,
        ]];
    }

    // ── Bulk discount ─────────────────────────────────────────────────────────

    private function applyBulkDiscount(int $slotCount, Product $room, float $amount): array
    {
        $rules = $room->bulk_discount_rules ?? [];

        if (empty($rules)) {
            return [0, null];
        }

        $matched = collect($rules)
            ->filter(fn ($r) => $slotCount >= (int) ($r['slots'] ?? 0))
            ->sortByDesc('slots')
            ->first();

        if (! $matched) {
            return [0, null];
        }

        $rate     = (float) ($matched['discount'] ?? 0) / 100;
        $discount = (int) ($amount * $rate);

        return [$discount, [
            'type'            => 'bulk',
            'label'           => "Đặt {$slotCount} khung giờ ({$matched['discount']}%)",
            'slots_required'  => (int) $matched['slots'],
            'discount_rate'   => $matched['discount'],
            'discount_amount' => $discount,
        ]];
    }

    // ── Helper: parse "10%" hoặc "50000" ─────────────────────────────────────

    private function parseDiscountRule(float $amount, string $rule): float
    {
        if (str_contains($rule, '%')) {
            $pct = (float) str_replace('%', '', $rule);
            return $amount * ($pct / 100);
        }

        return (float) str_replace(['.', ','], '', $rule);
    }

    // ── Promotions ────────────────────────────────────────────────────────────

    private function applyPromotions(Collection $rtsCollection, array $slotSummary = []): array
    {
        $calculator = new PromotionCalculator();
        $rtsList    = $rtsCollection->values();

        $totalDiscount = 0;
        $applied       = [];

        foreach ($rtsList as $i => $rts) {
            $bookingDate = $slotSummary[$i]['date'] ?? null;
            if (! $bookingDate || ! $rts->timeSlot) {
                continue;
            }

            $result = $calculator->calculate($rts, $bookingDate);
            $totalDiscount += $result['promo_discount'];

            foreach ($result['applied'] as $entry) {
                $found = false;
                foreach ($applied as $i => $a) {
                    if ($a['id'] === $entry['id']) {
                        $applied[$i]['discount_amount'] += $entry['discount_amount'];
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $applied[] = $entry;
                }
            }
        }

        return [(int) $totalDiscount, $applied];
    }

    private function applyDailyPromotions(Collection $rtsCollection, array $nightSummary): array
    {
        $calculator    = new PromotionCalculator();
        $nightPriceMap = collect($nightSummary)->pluck('price', 'date');

        $totalDiscount = 0;
        $applied       = [];

        foreach ($rtsCollection as $rts) {
            $date = $rts->timeSlot?->label;
            if (! $date || ! $nightPriceMap->has($date)) continue;

            $price  = (float) $nightPriceMap->get($date);
            $result = $calculator->calculateForDate($rts, $price, $date);
            $disc   = $price - $result['final_price'];
            $totalDiscount += $disc;

            foreach ($result['applied'] as $entry) {
                $found = false;
                foreach ($applied as $i => $a) {
                    if ($a['id'] === $entry['id']) {
                        $applied[$i]['discount_amount'] += $entry['discount_amount'];
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $applied[] = $entry;
                }
            }
        }

        return [(int) $totalDiscount, $applied];
    }

    // ── Phụ thu số lượng người ───────────────────────────────────────────────

    private function buildGuestSurcharge(Request $request, Product $room, array $slotSummary): array
    {
        if (empty($slotSummary)) {
            return [0, null];
        }

        $type = $request->input('type');

        if ($type === 'slot' && (int) $room->styles !== 1) {
            return [0, null];
        }

        $config    = $room->room_config ?? [];
        $fee       = (int) ($config['extra_guest_fee'] ?? 0);
        $threshold = (int) ($config['max_free_guests'] ?? 2);
        $guests    = (int) $request->guest_count;

        if ($fee <= 0 || $guests <= $threshold) {
            return [0, null];
        }

        $extraGuests = $guests - $threshold;
        $nights      = match (true) {
            $type === 'daily' => count($slotSummary),
            $type === 'slot'  => collect($slotSummary)->pluck('date')->unique()->count(),
            default           => 1,
        };
        $total       = $extraGuests * $fee * $nights;

        $label = "Phụ thu {$extraGuests} người (trên {$threshold} người)";
        if ($nights > 1) {
            $label .= " × {$nights} đêm";
        }

        return [$total, [
            'guest_count'    => $guests,
            'threshold'      => $threshold,
            'extra_guests'   => $extraGuests,
            'fee_per_person' => $fee,
            'nights'         => $nights,
            'total'          => $total,
            'label'          => $label,
        ]];
    }

    // ── Preview (dry-run) ─────────────────────────────────────────────────────

    /**
     * Tính giá đầy đủ (items + khuyến mãi + giảm giá + phụ thu khách + dịch vụ + cọc) mà KHÔNG tạo
     * item/order nào — dùng chung cho preview() ở BookingController (tạo mới) và OrderController
     * (đang sửa đơn). Chạy lại ĐÚNG các bước tính giá của store()/update() theo thứ tự, chỉ khác là
     * dừng lại trước bước ghi DB (không gọi $order->items()->create()/Order::create()).
     *
     * $excludeOrderId: đơn đang sửa (nếu có) — loại trừ chính order_items hiện có của đơn này khỏi
     * kiểm tra trùng khung giờ trong buildSlotItems/buildDailyItems/buildMonthlyItem, vì preview
     * không xoá items cũ trước như update() thật (dry-run, không đụng DB).
     * $paymentMethodForDeposit: dùng payment_method THẬT của đơn đang sửa để xét điều kiện cọc
     * (payment_type=deposit chặn nếu cod) — update() không cho đổi payment_method nên phải đọc từ
     * đơn hiện có, KHÔNG phải từ request (khác preview tạo mới, nơi payment_method nằm trong body).
     *
     * @throws ValidationException nếu khung giờ trùng, dịch vụ không hợp lệ, hoặc điều kiện cọc
     *         không thoả — Laravel tự chuyển thành response 422, giống hệt store()/update().
     */
    private function computeBookingPreview(
        Request $request,
        Product $room,
        ?int $excludeOrderId = null,
        ?string $paymentMethodForDeposit = null
    ): array {
        $type          = $request->input('type');
        $rtsCollection = collect();
        $slotSummary   = [];

        if ($type === 'slot') {
            [$basePrice, , , $rtsCollection, $slotSummary] = $this->buildSlotItems($request, $room, $excludeOrderId);
        } elseif ($type === 'daily') {
            [$basePrice, , , $rtsCollection, $slotSummary] = $this->buildDailyItems($request, $room, $excludeOrderId);
        } else {
            [$basePrice] = $this->buildMonthlyItem($request, $room, $excludeOrderId);
        }

        [$servicesTotal] = $this->buildServices($request, $room);
        [$guestSurcharge, $guestSurchargeInfo] = $this->buildGuestSurcharge($request, $room, $slotSummary);

        $promotionDiscount     = 0;
        $systemDiscount        = 0;
        $appliedSystemDiscount = null;

        $hasFullBooking = ! empty($slotSummary) && $this->checkFullDayBooking($slotSummary, $room);

        if ($hasFullBooking) {
            [$systemDiscount, $appliedSystemDiscount] = $this->applyFullBookingDiscount($basePrice, $room);
        } else {
            if ($rtsCollection->isNotEmpty()) {
                [$promotionDiscount] = $type === 'daily'
                    ? $this->applyDailyPromotions($rtsCollection, $slotSummary)
                    : $this->applyPromotions($rtsCollection, $slotSummary);
            }
            if (! empty($slotSummary)) {
                [$systemDiscount, $appliedSystemDiscount] = $this->applyBulkDiscount(
                    count($slotSummary),
                    $room,
                    $basePrice - $promotionDiscount
                );
            }
        }

        $discountAmount = $promotionDiscount + $systemDiscount;
        $slotFinalPrice = max(0, $basePrice - $discountAmount);
        $finalAmount    = $slotFinalPrice + $servicesTotal + $guestSurcharge;

        // ── Cọc (chỉ áp dụng type=daily, giống store()/update()) ──────────────
        $depositInfo = null;

        if ($type === 'daily' && $request->input('payment_type') === 'deposit') {
            $paymentMethod = $paymentMethodForDeposit ?? $request->input('payment_method', 'cod');

            if ($paymentMethod === 'cod') {
                throw ValidationException::withMessages([
                    'payment_type' => ['Đặt cọc không áp dụng cho phương thức thanh toán tiền mặt.'],
                ]);
            }

            $depositMin = (int) ($room->deposit_min_nights  ?? 0);
            $depositPct = (int) ($room->deposit_multi_night ?? 50);
            $nights     = count($slotSummary);

            if ($depositMin > 0 && $nights >= $depositMin && $depositPct < 100) {
                $depositAmount = (int) ceil($finalAmount * $depositPct / 100);
                $depositInfo   = [
                    'percentage'       => $depositPct,
                    'deposit_amount'   => $depositAmount,
                    'remaining_amount' => (int) $finalAmount - $depositAmount,
                ];
            } else {
                throw ValidationException::withMessages([
                    'payment_type' => [
                        'Đặt cọc không áp dụng' . ($depositMin > 0 ? " (cần tối thiểu {$depositMin} đêm)" : '') . '.',
                    ],
                ]);
            }
        }

        return [
            'guest_surcharge' => $guestSurchargeInfo,
            'system_discount' => $appliedSystemDiscount,
            'deposit'         => $depositInfo,
            'summary' => [
                'slots_total'          => (int) $basePrice,
                'promotion_discount'   => $promotionDiscount,
                'system_discount'      => $systemDiscount,
                'discount_amount'      => $discountAmount,
                'slots_final'          => $slotFinalPrice,
                'guest_surcharge'      => $guestSurcharge,
                'services_total'       => $servicesTotal,
                'total_after_discount' => (int) $finalAmount,
                'final_amount'         => (int) $finalAmount,
            ],
        ];
    }

    // ── PayOS ─────────────────────────────────────────────────────────────────

    private function createPayOSLink(Order $order, string $itemName, ?string $returnUrl = null, ?string $cancelUrl = null): void
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return;
            }

            $payOS     = new PayOS($clientId, $apiKey, $checksumKey);
            $expiredAt = now()->addMinutes(15);
            $dueNow    = $order->depositDueAmount();

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $order->order_code,
                'amount'      => $dueNow,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => $returnUrl ?? route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => $cancelUrl ?? route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => $dueNow]],
            ]);

            $updates = ['expired_at' => $expiredAt];

            if ($checkoutUrl = $response['checkoutUrl'] ?? null) {
                $updates['checkout_url'] = $checkoutUrl;
            }

            if ($qrCode = $response['qrCode'] ?? null) {
                $updates['qr_code'] = $qrCode;
            }

            if (! empty($updates)) {
                $order->update($updates);
            }
        } catch (\Throwable $e) {
            Log::error('PayOS link creation error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
