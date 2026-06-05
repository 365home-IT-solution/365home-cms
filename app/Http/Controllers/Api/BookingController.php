<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
            $baseRules['timeslot_id'] = 'required|integer';
            $baseRules['date']        = 'required|date_format:Y-m-d|after_or_equal:today';
        } else {
            $baseRules['checkin_date']  = 'required|date|after_or_equal:today';
            $baseRules['checkout_date'] = 'required|date|after:checkin_date';
        }

        $request->validate($baseRules);

        // ── 2. Khách hàng từ token ────────────────────────────────────────────
        /** @var \App\Models\Customer $customer */
        $customer   = auth('sanctum')->user();
        $buyerName  = $customer->fullname;
        $buyerPhone = $customer->phone;

        // ── 3. Load phòng + dịch vụ bổ sung ─────────────────────────────────
        $room = Product::where('id', $request->input('room_id'))
            ->where('is_activated', true)
            ->with(['roomType', 'roomTimeSlots.timeSlot', 'additionalServices'])
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại hoặc đã ngừng hoạt động.'], 404);
        }

        // ── 4. Xây dựng item đặt phòng ───────────────────────────────────────
        $rts = null;
        if ($request->type === 'slot') {
            [$basePrice, $itemName, $itemData, $rts] = $this->buildSlotItem($request, $room);
        } else {
            [$basePrice, $itemName, $itemData] = $this->buildMonthlyItem($request, $room);
        }

        // ── 5. Dịch vụ bổ sung ──────────────────────────────────────────────
        [$servicesTotal, $servicesData] = $this->buildServices($request, $room);

        $subtotalBeforeDiscount = $basePrice + $servicesTotal;

        // ── 6. Auto-apply promotions (chỉ với slot) ──────────────────────────
        $appliedPromotions = [];
        $promotionDiscount = 0;
        if ($rts !== null) {
            [$promotionDiscount, $appliedPromotions] = $this->applyPromotions($rts, $subtotalBeforeDiscount);
        }

        // ── 7. Mã giảm giá ──────────────────────────────────────────────────
        $appliedCoupon  = null;
        $couponDiscount = 0;
        if ($request->filled('coupon_code')) {
            [$couponDiscount, $appliedCoupon] = $this->applyCoupon(
                $request->coupon_code,
                $subtotalBeforeDiscount - $promotionDiscount,
                $room,
                $rts
            );
        }

        $fullAmount     = $subtotalBeforeDiscount;
        $discountAmount = $promotionDiscount + $couponDiscount;
        $amount         = max(0, $fullAmount - $discountAmount);

        $category      = $room->categories()->first();
        $paymentMethod = $request->input('payment_method', 'PayOS');

        // ── 8. Tạo đơn + items + services trong transaction ──────────────────
        $order = DB::transaction(function () use (
            $room, $amount, $fullAmount, $buyerName, $buyerPhone,
            $customer, $category, $itemData, $servicesData,
            $paymentMethod, $request, $appliedCoupon
        ) {
            $order = Order::create([
                'amount'         => $amount,
                'full_amount'    => $fullAmount,
                'description'    => 'Đặt phòng - ' . $room->name,
                'buyer_name'     => $buyerName,
                'buyer_phone'    => $buyerPhone,
                'payment_method' => $paymentMethod,
                'status'         => 'pending',
                'guest_count'    => $request->guest_count,
                'category_id'    => $category?->id,
                'customer_id'    => $customer?->id,
            ]);

            $order->items()->create($itemData);

            foreach ($servicesData as $svc) {
                $order->services()->create([
                    'service_id'   => $svc['service_id'],
                    'service_name' => $svc['service_name'],
                    'price'        => $svc['price'],
                    'quantity'     => $svc['quantity'],
                    'subtotal'     => $svc['subtotal'],
                ]);
            }

            if ($appliedCoupon) {
                $appliedCoupon->incrementUsage();
            }

            return $order;
        });

        // ── 9. Tạo link PayOS ────────────────────────────────────────────────
        if ($paymentMethod === 'PayOS' && $amount >= 2000) {
            $this->createPayOSLink($order, $itemName);
        }

        $order->refresh();

        return response()->json([
            'order' => [
                'id'              => $order->id,
                'order_code'      => $order->order_code,
                'full_amount'     => (int) $order->full_amount,
                'discount_amount' => $discountAmount,
                'amount'          => (int) $order->amount,
                'description'     => $order->description,
                'buyer_name'      => $order->buyer_name,
                'buyer_phone'     => $order->buyer_phone,
                'payment_method'  => $order->payment_method,
                'status'          => $order->status,
                'expired_at'      => $order->expired_at,
                'checkout_url'    => $order->checkout_url,
                'services'        => $servicesData,
                'promotions'      => $appliedPromotions,
                'coupon'          => $appliedCoupon ? [
                    'code'            => $appliedCoupon->code,
                    'name'            => $appliedCoupon->name,
                    'type'            => $appliedCoupon->type,
                    'value'           => $appliedCoupon->value,
                    'discount_amount' => $couponDiscount,
                ] : null,
            ],
        ], 201);
    }

    // ── Slot ─────────────────────────────────────────────────────────────────

    private function buildSlotItem(Request $request, Product $room): array
    {
        $timeslotId = (int) $request->timeslot_id;
        $dateStr    = $request->date;

        $rts = $room->roomTimeSlots
            ->filter(fn ($s) => is_null($s->date))
            ->where('timeslot_id', $timeslotId)
            ->first();

        if (! $rts || ! $rts->timeSlot) {
            throw ValidationException::withMessages([
                'timeslot_id' => ['Khung giờ không tồn tại cho phòng này.'],
            ]);
        }

        if ($rts->isBlockedOn($dateStr)) {
            throw ValidationException::withMessages([
                'date' => ['Khung giờ này đã bị chặn vào ngày bạn chọn.'],
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
                'timeslot_id' => ['Khung giờ này đã được đặt rồi.'],
            ]);
        }

        $startLabel  = substr($timeSlot->start_time, 0, 5);
        $endLabel    = substr($timeSlot->end_time, 0, 5);
        $isOvernight = (bool) $rts->over_night;
        $itemName    = $room->name . ' - ' . $startLabel . ' - ' . $endLabel . ($isOvernight ? ' (Qua đêm)' : '');
        $price       = (int) $rts->price;

        return [$price, $itemName, [
            'product_id'    => $room->id,
            'name'          => $itemName,
            'price'         => $price,
            'quantity'      => 1,
            'is_shipped'    => true,
            'checkin_date'  => $checkin,
            'checkout_date' => $checkout,
            'extra_fee'     => 0,
            'guest_count'   => $request->guest_count,
        ], $rts];
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

        return [$price, $itemName, [
            'product_id'    => $room->id,
            'name'          => $itemName,
            'price'         => $price,
            'quantity'      => 1,
            'is_shipped'    => true,
            'checkin_date'  => $checkin,
            'checkout_date' => $checkout,
            'extra_fee'     => 0,
            'guest_count'   => $request->guest_count,
        ]];
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

            $data[] = [
                'service_id'   => $service->id,
                'service_name' => $service->name,
                'price'        => (int) $service->price,
                'quantity'     => $quantity,
                'subtotal'     => (int) $subtotal,
            ];
        }

        return [$total, $data];
    }

    // ── Promotions (auto-apply từ slot) ───────────────────────────────────────

    private function applyPromotions(RoomTimeSlot $rts, float $orderAmount): array
    {
        $now = now();

        $promotions = $rts->promotions()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->get();

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
        ?RoomTimeSlot $rts
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
            'specific_slot' => $rts !== null && $coupon->isApplicableToSlot($rts),
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
                'amount'      => (int) $order->amount,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => (int) $order->amount]],
            ]);

            if ($checkoutUrl = $response['checkoutUrl'] ?? null) {
                $order->update(['checkout_url' => $checkoutUrl, 'expired_at' => $expiredAt]);
            }
        } catch (\Throwable $e) {
            Log::error('PayOS link creation error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
