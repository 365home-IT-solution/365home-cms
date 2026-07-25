<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Customer;
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
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Promotion\App\Models\Coupon;
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

    private function buildSlotItems(Request $request, Product $room): array
    {
        $slots         = $request->input('slots');
        $defaultDate   = $request->input('date');
        $totalPrice    = 0;
        $itemsData     = [];
        $slotSummary   = [];
        $rtsCollection = collect();

        foreach ($slots as $index => $slot) {
            $timeslotId = (int) $slot['timeslot_id'];
            $dateStr    = $slot['date'] ?? $defaultDate;

            if (! $dateStr) {
                throw ValidationException::withMessages([
                    "slots.{$index}.date" => ['Vui lòng cung cấp ngày đặt phòng.'],
                ]);
            }

            $rts = $room->roomTimeSlots
                ->filter(fn ($s) => is_null($s->date))
                ->where('timeslot_id', $timeslotId)
                ->first();

            if (! $rts || ! $rts->timeSlot) {
                throw ValidationException::withMessages([
                    "slots.{$index}.timeslot_id" => ['Khung giờ không tồn tại cho phòng này.'],
                ]);
            }

            if ($rts->isBlockedOn($dateStr)) {
                throw ValidationException::withMessages([
                    "slots.{$index}.date" => ['Khung giờ này đã bị chặn vào ngày bạn chọn.'],
                ]);
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
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    "slots.{$index}.timeslot_id" => ['Khung giờ này đã được đặt rồi.'],
                ]);
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

        $slotCount   = count($slots);
        $summaryName = $slotCount === 1
            ? $itemsData[0]['name']
            : $room->name . ' - ' . $slotCount . ' khung giờ';

        return [$totalPrice, $summaryName, $itemsData, $rtsCollection, $slotSummary];
    }

    // ── Daily (phòng theo ngày) ───────────────────────────────────────────────

    private function buildDailyItems(Request $request, Product $room): array
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

    private function buildMonthlyItem(Request $request, Product $room): array
    {
        $checkin  = Carbon::parse($request->checkin_date);
        $checkout = Carbon::parse($request->checkout_date);

        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
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

    // ── Coupons (chấp nhận cả coupon cá nhân nếu có $customer, hoặc chỉ coupon công
    //    khai nếu $customer null — dùng chung cho khách vãng lai lẫn khách thành viên) ──

    private function validateBookingCoupon(
        string $code,
        int $index,
        Product $room,
        Collection $rtsCollection,
        ?Customer $customer,
        float $orderAmount
    ): Coupon {
        $coupon = Coupon::where('code', $code)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->first();

        $field = "coupon_codes.{$index}";

        if (! $coupon) {
            throw ValidationException::withMessages([
                $field => ["Mã \"{$code}\" không tồn tại hoặc đã hết hạn."],
            ]);
        }

        $isRestricted = $coupon->customer_id !== null || $coupon->customers()->exists();
        if ($isRestricted) {
            $owns = $customer && (
                $coupon->customer_id === $customer->id
                || $coupon->customers()->where('customer_id', $customer->id)->exists()
            );

            if (! $owns) {
                throw ValidationException::withMessages([
                    $field => ["Mã \"{$code}\" không tồn tại hoặc đã hết hạn."],
                ]);
            }
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            throw ValidationException::withMessages([
                $field => ["Mã \"{$code}\" đã hết lượt sử dụng."],
            ]);
        }

        if ($coupon->min_order_value && $orderAmount < (float) $coupon->min_order_value) {
            throw ValidationException::withMessages([
                $field => ['Mã "' . $code . '" yêu cầu đơn hàng tối thiểu ' . number_format((float) $coupon->min_order_value) . 'đ.'],
            ]);
        }

        $applicable = match ($coupon->apply_type) {
            'all_rooms'     => true,
            'specific_room' => $coupon->room_id === $room->id,
            'specific_slot' => $rtsCollection->some(fn (RoomTimeSlot $rts) => $coupon->isApplicableToSlot($rts)),
            default         => false,
        };

        if (! $applicable) {
            throw ValidationException::withMessages([
                $field => ["Mã \"{$code}\" không áp dụng cho phòng hoặc khung giờ này."],
            ]);
        }

        return $coupon;
    }

    private function applyBookingCoupons(
        array $codes,
        float $orderAmount,
        Product $room,
        Collection $rtsCollection,
        ?Customer $customer
    ): array {
        if (empty($codes)) {
            return [0, []];
        }

        $codes = array_values(array_unique(array_map('strtoupper', $codes)));

        $coupons = [];
        foreach ($codes as $index => $code) {
            $coupons[] = $this->validateBookingCoupon($code, $index, $room, $rtsCollection, $customer, $orderAmount);
        }

        usort($coupons, fn ($a, $b) => ($b->type === 'percentage' ? 1 : 0) - ($a->type === 'percentage' ? 1 : 0));

        $totalDiscount = 0;
        $applied       = [];
        $remaining     = $orderAmount;

        foreach ($coupons as $coupon) {
            if ($remaining <= 0) {
                break;
            }

            $discount   = (int) $coupon->calculateDiscount($remaining);
            $remaining -= $discount;
            $totalDiscount += $discount;

            $applied[] = [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'type'            => $coupon->type,
                'value'           => $coupon->value,
                'discount_amount' => $discount,
                '_model'          => $coupon,
            ];
        }

        return [(int) $totalDiscount, $applied];
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
