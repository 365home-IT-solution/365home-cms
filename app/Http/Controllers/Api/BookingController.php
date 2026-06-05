<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Promotion\App\Models\Coupon;
use PayOS\PayOS;

class BookingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // ── 1. Validate ──────────────────────────────────────────────────────
        $baseRules = [
            'type'                    => 'required|in:slot,monthly',
            'room_id'                 => 'required|string',
            'guest_count'             => 'required|integer|min:1',
            'payment_method'          => 'sometimes|in:PayOS,cash',
            'coupon_code'             => 'sometimes|nullable|string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
        ];

        if ($request->input('type') === 'slot') {
            // date có thể đặt ở ngoài (dùng chung) hoặc trong từng slot (nhiều ngày)
            $baseRules['date']                = 'sometimes|date_format:Y-m-d|after_or_equal:today';
            $baseRules['slots']               = 'required|array|min:1';
            $baseRules['slots.*.timeslot_id'] = 'required|integer';
            $baseRules['slots.*.date']        = 'sometimes|date_format:Y-m-d|after_or_equal:today';
        } else {
            $baseRules['checkin_date']  = 'required|date|after_or_equal:today';
            $baseRules['checkout_date'] = 'required|date|after:checkin_date';
        }

        $request->validate($baseRules);

        // ── 2. Khách hàng từ token ───────────────────────────────────────────
        /** @var \App\Models\Customer $customer */
        $customer   = auth('sanctum')->user();
        $buyerName  = $customer->fullname;
        $buyerPhone = $customer->phone;

        // ── 3. Load phòng ─────────────────────────────────────────────────────
        $room = Product::where('id', $request->input('room_id'))
            ->where('is_activated', true)
            ->with([
                'roomType',
                'additionalServices',
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại hoặc đã ngừng hoạt động.'], 404);
        }

        // ── 4. Xây dựng items đặt phòng ──────────────────────────────────────
        $rtsCollection = collect();
        $slotSummary   = [];

        if ($request->input('type') === 'slot') {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildSlotItems($request, $room);
        } else {
            [$basePrice, $summaryName, $itemsData] = $this->buildMonthlyItem($request, $room);
        }

        // ── 5. Dịch vụ bổ sung ───────────────────────────────────────────────
        [$servicesTotal, $servicesData] = $this->buildServices($request, $room);

        $subtotal = $basePrice + $servicesTotal;

        // ── 6. Áp dụng discount theo thứ tự ưu tiên ─────────────────────────
        //
        //  Discount CHỈ tính trên giá slot (basePrice).
        //  Services được cộng vào SAU khi đã trừ hết discount.
        //
        //  Full booking (chọn hết slot trong ngày)
        //    → full_booking_discount + coupon, BỎ QUA promotion + bulk
        //
        //  Không full booking
        //    → promotion → bulk → coupon
        //
        $appliedPromotions     = [];
        $promotionDiscount     = 0;
        $appliedSystemDiscount = null; // full_booking hoặc bulk
        $systemDiscount        = 0;
        $appliedCoupon         = null;
        $couponDiscount        = 0;

        $hasFullBooking = ! empty($slotSummary) && $this->checkFullDayBooking($slotSummary, $room);

        if ($hasFullBooking) {
            // Full booking: áp discount trên basePrice, KHÔNG promotion/bulk
            [$systemDiscount, $appliedSystemDiscount] = $this->applyFullBookingDiscount($basePrice, $room);

            // Coupon tính trên giá sau full_booking_discount
            if ($request->filled('coupon_code')) {
                [$couponDiscount, $appliedCoupon] = $this->applyCoupon(
                    $request->coupon_code,
                    $basePrice - $systemDiscount,
                    $room,
                    $rtsCollection
                );
            }
        } else {
            // Promotion → bulk → coupon, tất cả tính trên basePrice
            if ($rtsCollection->isNotEmpty()) {
                [$promotionDiscount, $appliedPromotions] = $this->applyPromotions($rtsCollection, $basePrice, $slotSummary);
            }

            if (! empty($slotSummary)) {
                [$systemDiscount, $appliedSystemDiscount] = $this->applyBulkDiscount(
                    count($slotSummary),
                    $room,
                    $basePrice - $promotionDiscount
                );
            }

            if ($request->filled('coupon_code')) {
                [$couponDiscount, $appliedCoupon] = $this->applyCoupon(
                    $request->coupon_code,
                    $basePrice - $promotionDiscount - $systemDiscount,
                    $room,
                    $rtsCollection
                );
            }
        }

        // Services cộng vào SAU khi trừ hết discount trên slot
        $discountAmount = $promotionDiscount + $systemDiscount + $couponDiscount;
        $slotFinalPrice = max(0, $basePrice - $discountAmount);
        $finalAmount    = $slotFinalPrice + $servicesTotal;

        $category      = $room->categories()->first();
        $paymentMethod = $request->input('payment_method', 'PayOS');

        // ── 7. Tạo đơn + items + services trong transaction ──────────────────
        $order = DB::transaction(function () use (
            $room, $finalAmount, $subtotal, $buyerName, $buyerPhone,
            $customer, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupon
        ) {
            $order = Order::create([
                'amount'         => $subtotal,    // giá gốc: slots + services (trước giảm giá)
                'full_amount'    => $finalAmount, // giá thực tế phải trả (sau giảm giá)
                'description'    => 'Đặt phòng - ' . $room->name,
                'buyer_name'     => $buyerName,
                'buyer_phone'    => $buyerPhone,
                'payment_method' => $paymentMethod,
                'status'         => 'pending',
                'guest_count'    => $request->guest_count,
                'category_id'    => $category?->id,
                'customer_id'    => $customer?->id,
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            if ($appliedCoupon) {
                $appliedCoupon->incrementUsage();
            }

            return $order;
        });

        // ── 8. Tạo link PayOS ────────────────────────────────────────────────
        if ($paymentMethod === 'PayOS' && $finalAmount >= 2000) {
            $this->createPayOSLink($order, $summaryName);
        }

        $order->refresh();

        return response()->json([
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'checkout_url'   => $order->checkout_url,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
            ],
            'room' => [
                'id'   => $room->id,
                'name' => $room->name,
            ],
            'slots'           => $slotSummary,
            'services'        => $servicesData,
            'promotions'      => $appliedPromotions,
            'system_discount' => $appliedSystemDiscount,
            'coupon'          => $appliedCoupon ? [
                'code'            => $appliedCoupon->code,
                'name'            => $appliedCoupon->name,
                'type'            => $appliedCoupon->type,
                'value'           => $appliedCoupon->value,
                'discount_amount' => $couponDiscount,
            ] : null,
            'summary' => [
                'slots_total'        => $basePrice,
                'promotion_discount' => $promotionDiscount,
                'system_discount'    => $systemDiscount,
                'coupon_discount'    => $couponDiscount,
                'discount_amount'    => $discountAmount,
                'slots_final'        => $slotFinalPrice,
                'services_total'     => $servicesTotal,
                'final_amount'       => (int) $order->full_amount,
            ],
        ], 201);
    }

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

    // ── Monthly ───────────────────────────────────────────────────────────────

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

    // ── Full booking check ────────────────────────────────────────────────────

    private function checkFullDayBooking(array $slotSummary, Product $room): bool
    {
        if (empty($room->full_booking_discount)) {
            return false;
        }

        // Tổng số template slot của phòng (date IS NULL)
        $totalSlots = $room->roomTimeSlots
            ->filter(fn ($s) => is_null($s->date))
            ->count();

        if ($totalSlots === 0) {
            return false;
        }

        // Nhóm các slot đã chọn theo ngày
        $slotsByDate = collect($slotSummary)->groupBy('date');

        // Full booking = bất kỳ ngày nào có đủ tất cả slot
        foreach ($slotsByDate as $dateSlots) {
            if ($dateSlots->count() === $totalSlots) {
                return true;
            }
        }

        return false;
    }

    // ── Full booking discount ─────────────────────────────────────────────────

    private function applyFullBookingDiscount(float $amount, Product $room): array
    {
        $rule     = $room->full_booking_discount;
        $discount = (int) $this->parseDiscountRule($amount, $rule);

        $info = [
            'type'            => 'full_booking',
            'label'           => 'Đặt cả ngày',
            'rule'            => $rule,
            'discount_amount' => $discount,
        ];

        return [$discount, $info];
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

        $info = [
            'type'            => 'bulk',
            'label'           => "Đặt {$slotCount} khung giờ ({$matched['discount']}%)",
            'slots_required'  => (int) $matched['slots'],
            'discount_rate'   => $matched['discount'],
            'discount_amount' => $discount,
        ];

        return [$discount, $info];
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

    // ── Promotions (auto-apply, gộp từ tất cả slot) ──────────────────────────

    private function applyPromotions(Collection $rtsCollection, float $orderAmount, array $slotSummary = []): array
    {
        // Build map timeslot_id => booking date để kiểm tra overlap đúng ngày đặt
        $slotDateMap = collect($slotSummary)->pluck('date', 'timeslot_id');

        $promotions = collect();
        foreach ($rtsCollection as $rts) {
            $bookingDate = $slotDateMap->get($rts->timeslot_id);

            $rts->promotions
                ->filter(function ($p) use ($rts, $bookingDate) {
                    if (! $p->start_at || ! $p->end_at) {
                        return false;
                    }
                    if (! $bookingDate || ! $rts->timeSlot) {
                        return false;
                    }
                    // Kiểm tra overlap giữa thời gian slot đặt và khoảng thời gian promotion
                    // (giống promotionOverlapsSlot() của RoomController)
                    $slotStart = Carbon::parse("{$bookingDate} {$rts->timeSlot->start_time}");
                    $slotEnd   = Carbon::parse("{$bookingDate} {$rts->timeSlot->end_time}");
                    if ($slotEnd->lte($slotStart)) {
                        $slotEnd->addDay();
                    }
                    return $slotStart->lt($p->end_at) && $slotEnd->gt($p->start_at);
                })
                ->each(function ($p) use ($promotions) {
                    if (! $promotions->contains('id', $p->id)) {
                        $promotions->push($p);
                    }
                });
        }

        $totalDiscount = 0;
        $applied       = [];

        foreach ($promotions as $promotion) {
            $discount = $promotion->type === 'percentage'
                ? ($orderAmount * (float) $promotion->value) / 100
                : (float) $promotion->value;

            $discount = min($discount, $orderAmount - $totalDiscount);
            if ($discount <= 0) {
                continue;
            }

            $totalDiscount += $discount;
            $applied[] = [
                'name'            => $promotion->name,
                'type'            => $promotion->type,
                'value'           => $promotion->value,
                'discount_amount' => (int) $discount,
            ];
        }

        return [(int) $totalDiscount, $applied];
    }

    // ── Coupon ────────────────────────────────────────────────────────────────

    private function applyCoupon(
        string $code,
        float $orderAmount,
        Product $room,
        Collection $rtsCollection
    ): array {
        $coupon = Coupon::where('code', strtoupper($code))
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Mã giảm giá không tồn tại hoặc đã hết hạn.'],
            ]);
        }

        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Mã giảm giá đã hết lượt sử dụng.'],
            ]);
        }

        if ($coupon->min_order_value && $orderAmount < (float) $coupon->min_order_value) {
            throw ValidationException::withMessages([
                'coupon_code' => [
                    'Đơn hàng chưa đạt giá trị tối thiểu ' . number_format((float) $coupon->min_order_value) . 'đ để áp dụng mã này.',
                ],
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
                'coupon_code' => ['Mã giảm giá không áp dụng cho phòng hoặc khung giờ này.'],
            ]);
        }

        $discount = (int) $coupon->calculateDiscount($orderAmount);

        return [$discount, $coupon];
    }

    // ── PayOS ─────────────────────────────────────────────────────────────────

    private function createPayOSLink(Order $order, string $itemName): void
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

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $order->order_code,
                'amount'      => (int) $order->full_amount,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => (int) $order->full_amount]],
            ]);

            if ($checkoutUrl = $response['checkoutUrl'] ?? null) {
                $order->update(['checkout_url' => $checkoutUrl, 'expired_at' => $expiredAt]);
            }
        } catch (\Throwable $e) {
            Log::error('PayOS link creation error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
