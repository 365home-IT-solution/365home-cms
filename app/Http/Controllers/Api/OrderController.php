<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\PromotionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;
use PayOS\PayOS;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $orders = Order::with(['items.product.media', 'services'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json([
            'data' => $orders->getCollection()->map(fn ($o) => $this->buildListItem($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    // GET /api/orders/{order_code}
    public function show(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with([
            'items.product.media',
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
            'accessCodes',
        ])
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        return response()->json($this->buildDetail($order));
    }

    // ─────────────────────────────────────────────

    /**
     * POST /api/orders/{order_code}
     * Cập nhật thông tin người mua, dịch vụ bổ sung, và/hoặc danh sách mã giảm giá.
     * Chỉ áp dụng khi đơn ở trạng thái pending.
     */
    public function update(Request $request, string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
        ])
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể cập nhật khi đơn đang ở trạng thái pending.'], 422);
        }

        $request->validate([
            'guest_count'             => 'sometimes|integer|min:1|max:50',
            'note_for_admin'          => 'sometimes|nullable|string|max:500',
            'services'                => 'sometimes|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'coupon_code'             => 'sometimes|nullable',
        ]);

        $updates            = [];
        $originalFullAmount = (int) $order->full_amount;

        foreach (['guest_count', 'note_for_admin'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $request->input($field);
            }
        }

        // ── Phụ thu khách ─────────────────────────────────────────────────────
        if ($request->has('guest_count')) {
            $newGuestCount = (int) $request->input('guest_count');
            $order->items()->update(['guest_count' => $newGuestCount]);

            $guestRoom      = $order->items->first()?->product;
            $guestConfig    = $guestRoom?->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);

            if ($guestFee > 0) {
                $itemsSum       = (int) $order->items->sum('price');
                $oldServicesSum = (int) $order->services->sum('subtotal');
                $oldSurcharge   = max(0, (int) $order->amount - $itemsSum - $oldServicesSum);
                $isSlotType     = (int) $guestRoom?->styles === 1;
                $nights         = $isSlotType ? $this->countNights($order->items) : max(1, $order->items->count());
                $newSurcharge   = max(0, $newGuestCount - $guestThreshold) * $guestFee * $nights;

                if ($newSurcharge !== $oldSurcharge) {
                    $newAmtWithSurcharge = max(0, (int) $order->amount - $oldSurcharge + $newSurcharge);
                    $updates['amount']   = $newAmtWithSurcharge;

                    $gcPromoRtsMap = collect();
                    if ($guestRoom) {
                        $gcPromoRtsMap = $guestRoom->roomTimeSlots
                            ->whereNull('date')
                            ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
                    }
                    [, $gcRecomputedPromo] = $this->recomputePromotions($order->items, $gcPromoRtsMap);
                    $gcCouponDiscount = $this->recomputeCouponDiscount($order, $newAmtWithSurcharge - $gcRecomputedPromo);

                    $gcTotalDiscount = $gcRecomputedPromo + $gcCouponDiscount;

                    $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
                    if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                        $updates['full_amount'] = (int) ceil(max(0, $newAmtWithSurcharge - $gcTotalDiscount) * $depositPct / 100);
                    } else {
                        $updates['full_amount'] = max(0, $newAmtWithSurcharge - $gcTotalDiscount);
                    }

                    Log::info('order.update guest-surcharge', [
                        'order_code'     => $order->order_code,
                        'old_surcharge'  => $oldSurcharge,
                        'new_surcharge'  => $newSurcharge,
                        'promo_discount' => $gcRecomputedPromo,
                        'coupon_discount'=> $gcCouponDiscount,
                        'new_full_amount'=> $updates['full_amount'],
                    ]);

                    if ($order->checkout_url) {
                        $updates['checkout_url'] = null;
                        $updates['qr_code']      = null;
                        $updates['expired_at']   = null;
                    }
                }
            }
        }

        // ── Cập nhật coupon_codes ─────────────────────────────────────────────
        if ($request->has('coupon_codes') || $request->has('coupon_code')) {
            // Normalize: nhận coupon_codes (preferred) hoặc coupon_code (fallback)
            $couponInput = $request->input('coupon_codes');
            if ($couponInput === null && $request->has('coupon_code')) {
                $raw = $request->input('coupon_code');
                $couponInput = is_array($raw) ? $raw : ($raw !== null ? [$raw] : []);
            }
            $newCodes = array_values(array_unique(array_map(
                'strtoupper',
                array_filter((array) ($couponInput ?? []))
            )));

            // Giải phóng lượt dùng của coupon cũ
            $this->releaseCouponUsage($order);

            // Validate & apply coupon mới
            $product        = $order->items->first()?->product;
            $currentAmount  = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;

            // Tính promo discount để biết base cho coupon
            $rtsMap = collect();
            if ($product) {
                $rtsMap = $product->roomTimeSlots
                    ->whereNull('date')
                    ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
            }
            /** @var \Illuminate\Database\Eloquent\Collection $orderItems */
            $orderItems = $order->items;
            [, $promoDiscount] = $this->recomputePromotions($orderItems, $rtsMap);
            $couponBase = max(0, $currentAmount - $promoDiscount);

            [$newCouponDiscount, $appliedCoupons] = $this->applyAndValidateCoupons(
                $newCodes, $couponBase, $product, $customer
            );

            // Increment usage cho coupon mới
            foreach ($appliedCoupons as $info) {
                $info['_model']->incrementUsage();
            }

            $appliedCodes = collect($appliedCoupons)->pluck('code')->values()->all();
            $updates['coupon_code']  = $appliedCodes[0] ?? null;
            $updates['coupon_codes'] = $appliedCodes ?: null;

            // Tính lại full_amount với coupon mới
            $baseAmt     = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;
            $totalDisc   = $promoDiscount + $newCouponDiscount;
            $depositPct  = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
            if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                $updates['full_amount'] = (int) ceil(max(0, $baseAmt - $totalDisc) * $depositPct / 100);
            } else {
                $updates['full_amount'] = max(0, $baseAmt - $totalDisc);
            }

            if ($order->checkout_url) {
                $updates['checkout_url'] = null;
                $updates['qr_code']      = null;
                $updates['expired_at']   = null;
            }
        }

        // ── Cập nhật services ─────────────────────────────────────────────────
        if ($request->has('services')) {
            $productId = $order->items->first()?->product_id;
            if (! $productId) {
                return response()->json(['message' => 'Đơn hàng không có phòng.'], 422);
            }

            $room = \Modules\Product\App\Models\Product::where('id', $productId)
                ->with('additionalServices')
                ->first();

            if (! $room) {
                return response()->json(['message' => 'Phòng không tồn tại.'], 404);
            }

            $availableServices = $room->additionalServices->keyBy('id');
            $servicesData      = [];
            $addedTotal        = 0;

            foreach ($request->input('services') as $index => $entry) {
                $serviceId = (int) $entry['service_id'];
                $quantity  = (int) $entry['quantity'];
                $service   = $availableServices->get($serviceId);

                if (! $service || ! $service->is_active) {
                    throw ValidationException::withMessages([
                        "services.{$index}.service_id" => ["Dịch vụ #{$serviceId} không tồn tại hoặc không khả dụng cho phòng này."],
                    ]);
                }

                $subtotal       = $service->price * $quantity;
                $addedTotal    += $subtotal;
                $servicesData[] = [
                    'service_id'   => $service->id,
                    'service_name' => $service->name,
                    'price'        => (int) $service->price,
                    'quantity'     => $quantity,
                    'subtotal'     => (int) $subtotal,
                ];
            }

            $oldServicesTotal = (int) $order->services->sum('subtotal');

            $order->services()->delete();
            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            $currentAmountBase = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;
            $roomBaseAmount    = max(0, $currentAmountBase - $oldServicesTotal);
            $newSubtotal       = $roomBaseAmount + $addedTotal;

            $promoRtsMap = collect();
            $promoProduct = $order->items->first()?->product;
            if ($promoProduct) {
                $promoRtsMap = $promoProduct->roomTimeSlots
                    ->whereNull('date')
                    ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
            }
            [, $recomputedPromoDiscount] = $this->recomputePromotions($order->items, $promoRtsMap);

            // Lấy coupon codes từ updates (nếu vừa thay đổi) hoặc từ order cũ
            $couponCodesToUse = $updates['coupon_codes'] ?? ($order->coupon_codes ?: ($order->coupon_code ? [$order->coupon_code] : []));
            $couponBase       = max(0, $newSubtotal - $recomputedPromoDiscount);
            $recomputedCouponDiscount = $this->recomputeCouponDiscountFromCodes(
                is_array($couponCodesToUse) ? $couponCodesToUse : [],
                $couponBase
            );

            $totalNewDiscount = $recomputedPromoDiscount + $recomputedCouponDiscount;
            $depositPct       = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;

            if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                $newFullAmount = (int) ceil(max(0, $newSubtotal - $totalNewDiscount) * $depositPct / 100);
            } else {
                $newFullAmount = max(0, $newSubtotal - $totalNewDiscount);
            }

            Log::info('order.update services-recalc', [
                'order_code'          => $order->order_code,
                'old_services'        => $oldServicesTotal,
                'added_total'         => $addedTotal,
                'new_subtotal'        => $newSubtotal,
                'promo_discount'      => $recomputedPromoDiscount,
                'coupon_discount'     => $recomputedCouponDiscount,
                'total_discount'      => $totalNewDiscount,
                'new_full_amount'     => $newFullAmount,
            ]);

            $updates['amount']      = $newSubtotal;
            $updates['full_amount'] = $newFullAmount;

            if ($newFullAmount !== (int) $order->full_amount && $order->checkout_url) {
                $updates['checkout_url'] = null;
                $updates['qr_code']      = null;
                $updates['expired_at']   = null;
            }
        }

        if (! empty($updates)) {
            $order->update($updates);
            $order->refresh();
        }

        // Tạo lại link PayOS nếu giá thay đổi
        $priceChanged = (int) $order->full_amount !== $originalFullAmount;
        if (
            $order->payment_method === 'PayOS' &&
            ($priceChanged || ! $order->checkout_url) &&
            (int) $order->full_amount >= 2000
        ) {
            $itemName = $order->items->first()?->name ?? 'Đặt phòng';
            $this->buildPayOSLink($order, $itemName);
            $order->refresh();
        }

        $order->load([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
        ]);

        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        $slots = $order->items->map(fn ($item) => [
            'date'  => $item->checkin_date?->format('Y-m-d'),
            'price' => (int) $item->price,
        ])->values()->toArray();

        $servicesResult = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => (int) $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => (int) $s->subtotal,
        ])->values()->toArray();
        $servicesTotal = array_sum(array_column($servicesResult, 'subtotal'));

        // Guest surcharge
        $guestSurchargeInfo  = null;
        $guestSurchargeTotal = 0;
        if ($product) {
            $guestConfig    = $product->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);
            $isSlotType     = (int) $product->styles === 1;
            $nights         = $isSlotType ? $this->countNights($order->items) : max(1, $order->items->count());
            $guestCount     = (int) $order->guest_count;
            $extraGuests    = max(0, $guestCount - $guestThreshold);
            $guestSurchargeTotal = $extraGuests * $guestFee * $nights;

            if ($guestFee > 0) {
                $nightsLabel = (! $isSlotType && $nights > 1) ? " × {$nights} đêm" : '';
                $guestSurchargeInfo = [
                    'guest_count'    => $guestCount,
                    'threshold'      => $guestThreshold,
                    'extra_guests'   => $extraGuests,
                    'fee_per_person' => $guestFee,
                    'nights'         => $nights,
                    'total'          => $guestSurchargeTotal,
                    'label'          => "Phụ thu {$extraGuests} người (trên {$guestThreshold} người){$nightsLabel}",
                ];
            }
        }

        // Promotions
        $rtsMap = collect();
        if ($product) {
            $rtsMap = $product->roomTimeSlots
                ->whereNull('date')
                ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
        }
        [$promotions, $promotionDiscount] = $this->recomputePromotions($order->items, $rtsMap);

        // Summary
        $slotsTotal     = (int) $order->items->sum('price');
        $newFullAmt     = (int) $order->full_amount;
        $depositPctResp = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;

        if ($depositPctResp !== null && $depositPctResp > 0 && $depositPctResp < 100) {
            $realFinalAmount = (int) round($newFullAmt * 100 / $depositPctResp);
            $totalDiscount   = max(0, (int) $order->amount - $realFinalAmount);
        } else {
            $realFinalAmount = $newFullAmt;
            $totalDiscount   = max(0, (int) $order->amount - $newFullAmt);
        }

        $otherDiscount = max(0, $totalDiscount - $promotionDiscount);
        $slotsFinal    = max(0, $slotsTotal - $totalDiscount);

        // Coupons info
        $couponsInfo    = $this->buildCouponsInfo($order, $slotsTotal - $promotionDiscount);
        $couponDiscount = array_sum(array_column($couponsInfo, 'discount_amount'));
        $sysDelta       = max(0, $otherDiscount - $couponDiscount);

        return response()->json([
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'qr_code'        => $order->qr_code,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
                'note_for_admin' => $order->note_for_admin,
            ],
            'room' => [
                'id'   => $product?->id,
                'name' => $product?->name,
            ],
            'slots'           => $slots,
            'services'        => $servicesResult,
            'guest_surcharge' => $guestSurchargeInfo,
            'promotions'      => $promotions,
            'system_discount' => $sysDelta > 0 ? ['discount_amount' => $sysDelta] : null,
            'coupons'         => $couponsInfo,
            'deposit'         => $depositPctResp !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPctResp,
                'deposit_amount'   => $newFullAmt,
                'remaining_amount' => max(0, $realFinalAmount - $newFullAmt) + (int) ($order->extra_charge_amount ?? 0),
            ] : null,
            'summary' => [
                'slots_total'          => $slotsTotal,
                'promotion_discount'   => $promotionDiscount,
                'system_discount'      => $sysDelta,
                'coupon_discount'      => $couponDiscount,
                'discount_amount'      => $totalDiscount,
                'slots_final'          => $slotsFinal,
                'guest_surcharge'      => $guestSurchargeTotal,
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinalAmount,
                'final_amount'         => $newFullAmt,
            ],
        ]);
    }

    // ─────────────────────────────────────────────

    /**
     * GET /api/orders/{order_code}/payment-qr
     * Trả về QR hiện tại (nếu còn hạn) hoặc tự tạo lại nếu đã hết hạn.
     */
    public function paymentQr(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if (! in_array($order->status, ['pending', 'cancelled_payment'])) {
            return response()->json(['message' => 'Đơn hàng không ở trạng thái chờ thanh toán.'], 422);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json(['message' => 'Đơn này không thanh toán qua PayOS.'], 422);
        }

        if ((int) $order->full_amount < 2000) {
            return response()->json(['message' => 'Số tiền thanh toán không đủ tối thiểu.'], 422);
        }

        $needsNew = ! $order->qr_code
            || ! $order->expired_at
            || now()->gte($order->expired_at);

        if ($needsNew) {
            $itemName = $order->items->first()?->name ?? 'Đặt phòng';
            $this->buildPayOSLink($order, $itemName);
            $order->refresh();
        }

        if (! $order->qr_code) {
            return response()->json(['message' => 'Không thể tạo QR thanh toán. Vui lòng thử lại sau.'], 500);
        }

        return response()->json([
            'order_code' => $order->order_code,
            'status'     => $order->status,
            'qr_code'    => $order->qr_code,
            'expired_at' => $order->expired_at,
        ]);
    }

    /**
     * POST /api/orders/{order_code}/retry-payment
     */
    public function retryPayment(Request $request, string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if (! in_array($order->status, ['pending', 'cancelled_payment'])) {
            return response()->json([
                'message' => 'Chỉ có thể tạo lại link khi đơn ở trạng thái pending hoặc đã hết hạn thanh toán.',
            ], 422);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json(['message' => 'Đơn này không thanh toán qua PayOS.'], 422);
        }

        if ((int) $order->full_amount < 2000) {
            return response()->json(['message' => 'Số tiền thanh toán không đủ tối thiểu.'], 422);
        }

        $itemName    = $order->items->first()?->name ?? 'Đặt phòng';
        $checkoutUrl = $this->buildPayOSLink($order, $itemName, $request->input('return_url'), $request->input('cancel_url'));

        if (! $checkoutUrl) {
            return response()->json(['message' => 'Không thể tạo link thanh toán. Vui lòng thử lại sau.'], 500);
        }

        $order->update(['status' => 'pending']);
        $order->refresh();

        return response()->json([
            'order_code' => $order->order_code,
            'status'     => $order->status,
            'qr_code'    => $order->qr_code,
            'expired_at' => $order->expired_at,
        ]);
    }

    public function remainingPayment(Request $request, string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if ($order->status !== 'deposit') {
            return response()->json(['message' => 'Chỉ áp dụng cho đơn đang ở trạng thái đặt cọc.'], 422);
        }

        $depositPct  = (int) $order->deposit_percent;
        $depositPaid = (int) $order->full_amount;
        $realTotal   = $depositPct > 0 ? (int) round($depositPaid * 100 / $depositPct) : $depositPaid;
        $extraCharge = (int) ($order->extra_charge_amount ?? 0);
        $remaining   = ($realTotal - $depositPaid) + $extraCharge;

        if ($order->remaining_checkout_url && $order->remaining_payos_code) {
            return response()->json([
                'order_code' => $order->order_code,
                'qr_code'    => $order->remaining_qr_code,
                'amount'     => $remaining,
            ]);
        }

        if ($remaining < 2000) {
            return response()->json(['message' => 'Số tiền còn lại quá nhỏ hoặc đã thanh toán đủ.'], 422);
        }

        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return response()->json(['message' => 'Cổng thanh toán chưa được cấu hình.'], 500);
            }

            $payOS         = new PayOS($clientId, $apiKey, $checksumKey);
            $remainingCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
            $expiredAt     = now()->addMinutes(30);

            $response = $payOS->createPaymentLink([
                'orderCode'   => $remainingCode,
                'amount'      => $remaining,
                'description' => 'Tt con lai - ' . $order->order_code,
                'returnUrl'   => $request->input('return_url') ?? config('app.url') . '/payment/success?orderCode=' . $order->order_code . '&remaining=1',
                'cancelUrl'   => $request->input('cancel_url') ?? config('app.url') . '/payment/cancel?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [[
                    'name'     => 'Tiền còn lại - ' . ($order->items->first()?->name ?? 'Phòng'),
                    'quantity' => 1,
                    'price'    => $remaining,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;
            $qrCode      = $response['qrCode'] ?? null;

            if (! $checkoutUrl) {
                return response()->json(['message' => 'Không thể tạo link thanh toán.'], 500);
            }

            $order->update([
                'remaining_payos_code'   => $remainingCode,
                'remaining_checkout_url' => $checkoutUrl,
                'remaining_qr_code'      => $qrCode,
            ]);

            return response()->json([
                'order_code' => $order->order_code,
                'qr_code'    => $qrCode,
                'amount'     => $remaining,
                'expired_at' => $expiredAt->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('remainingPayment API error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Lỗi khi tạo link thanh toán. Vui lòng thử lại.'], 500);
        }
    }

    /**
     * GET /api/orders/{order_code}/payment-status
     */
    public function paymentStatus(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if (in_array($order->status, ['paid', 'failed', 'cancelled'])) {
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }

        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return response()->json(['order_code' => $order->order_code, 'status' => $order->status]);
            }

            $isRemaining = $order->status === 'deposit' && $order->remaining_payos_code;
            $payosCode   = $isRemaining
                ? (int) $order->remaining_payos_code
                : (int) $order->order_code;

            $payOS    = new PayOS($clientId, $apiKey, $checksumKey);
            $response = $payOS->getPaymentLinkInformation($payosCode);
            $status   = $response['status'] ?? 'PENDING';

            switch ($status) {
                case 'PAID':
                    if ($isRemaining) {
                        // Tính full_amount đúng (deposit + remaining) để Observer tích điểm chính xác
                        $depositPct  = (int) ($order->deposit_percent ?? 0);
                        $depositPaid = (int) $order->full_amount;
                        $realTotal   = $depositPct > 0
                            ? (int) round($depositPaid * 100 / $depositPct)
                            : $depositPaid;
                        $extraCharge = (int) ($order->extra_charge_amount ?? 0);
                        $totalPaid   = $realTotal + $extraCharge;

                        $order->update([
                            'status'                   => 'paid',
                            'full_amount'              => $totalPaid,
                            'amount'                   => $totalPaid,
                            'remaining_paid_at'        => now(),
                            'remaining_payment_method' => 'payos',
                        ]);
                        try {
                            $order->load('items.product');
                            $firstItem    = $order->items->sortBy('checkin_date')->first();
                            $checkinDate  = $order->items->min('checkin_date');
                            $checkoutDate = $order->items->max('checkout_date');
                            $product      = $firstItem?->product;
                            if (! $order->hasAccessCode()) {
                                app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)
                                    ->assignCodeToOrder($order->id, $order->category_id, $checkinDate, $checkoutDate, $product);
                            }
                        } catch (\Throwable $codeErr) {
                            Log::warning('Could not assign access code after remaining PayOS payment', [
                                'order_code' => $order->order_code,
                                'error'      => $codeErr->getMessage(),
                            ]);
                        }
                    } elseif ($order->deposit_percent !== null) {
                        $order->update(['status' => 'deposit', 'checkout_url' => null, 'deposit_paid_at' => now()]);
                    } else {
                        $order->update(['status' => 'paid']);
                    }
                    break;

                case 'CANCELLED':
                    if (! in_array($order->status, ['paid', 'deposit'])) {
                        $order->update(['status' => 'failed']);
                    }
                    break;

                case 'EXPIRED':
                    if (! in_array($order->status, ['paid', 'deposit'])) {
                        $order->update(['status' => 'cancelled_payment', 'checkout_url' => null]);
                    }
                    break;
            }

            $order->refresh();

            return response()->json([
                'order_code'   => $order->order_code,
                'status'       => $order->status,
                'payos_status' => $status,
                'expired_at'   => $order->expired_at,
            ]);

        } catch (\Throwable $e) {
            Log::error('paymentStatus API error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }
    }

    // ─────────────────────────────────────────────

    private function buildPayOSLink(Order $order, string $itemName, ?string $returnUrl = null, ?string $cancelUrl = null): ?string
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return null;
            }

            $payOS     = new PayOS($clientId, $apiKey, $checksumKey);
            $expiredAt = now()->addMinutes(15);

            $oldPayosCode = $order->current_payos_code ?? (int) $order->order_code;
            try {
                $payOS->cancelPaymentLink((int) $oldPayosCode);
            } catch (\Throwable $e) {
                Log::info('buildPayOSLink: cancel skipped', [
                    'order_code' => $order->order_code,
                    'reason'     => $e->getMessage(),
                ]);
            }

            $newPayosCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));

            $response = $payOS->createPaymentLink([
                'orderCode'   => $newPayosCode,
                'amount'      => (int) $order->full_amount,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => $returnUrl ?? route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => $cancelUrl ?? route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => (int) $order->full_amount]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;
            $qrCode      = $response['qrCode'] ?? null;

            if ($checkoutUrl) {
                $order->update([
                    'checkout_url'       => $checkoutUrl,
                    'qr_code'            => $qrCode,
                    'expired_at'         => $expiredAt,
                    'current_payos_code' => $newPayosCode,
                ]);
            }

            return $checkoutUrl;

        } catch (\Throwable $e) {
            Log::error('buildPayOSLink error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────

    private function buildListItem(Order $order): array
    {
        $firstItem = $order->items->first();
        $lastItem  = $order->items->last();

        $roomName = $firstItem?->product?->name
            ?? ($firstItem?->name ? explode(' - ', $firstItem->name, 2)[0] : null);

        $hasPendingExtraCharge = $order->extra_charge_amount && is_null($order->extra_charge_paid_at);

        return [
            'order_code'                => $order->order_code,
            'created_at'                => $order->created_at->format('Y-m-d H:i:s'),
            'status'                    => $order->status,
            'room_id'                   => $firstItem?->product?->id,
            'room_slug'                 => $firstItem?->product?->slug,
            'room_name'                 => $roomName,
            'room_thumbnail'            => $this->getRoomThumbnail($firstItem?->product),
            'checkin'                   => $firstItem?->checkin_date?->format('Y-m-d H:i'),
            'checkout'                  => $lastItem?->checkout_date?->format('Y-m-d H:i'),
            'final_amount'              => (int) $order->full_amount,
            'has_pending_extra_charge' => $hasPendingExtraCharge,
            'extra_charge_amount'      => $hasPendingExtraCharge ? (int) $order->extra_charge_amount : null,
        ];
    }

    private function buildDetail(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        $rtsMap = collect();
        if ($product) {
            $rtsMap = $product->roomTimeSlots
                ->whereNull('date')
                ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
        }

        $slots = $order->items->map(function ($item) use ($rtsMap) {
            $startTime = $item->checkin_date?->format('H:i:s');
            $rts       = $startTime ? $rtsMap->get($startTime) : null;

            $nameParts = $item->name ? explode(' - ', $item->name, 2) : [];
            $label     = count($nameParts) > 1 ? $nameParts[1] : null;

            return [
                'timeslot_id' => $rts?->timeslot_id,
                'date'        => $item->checkin_date?->format('Y-m-d'),
                'label'       => $label,
                'price'       => (int) $item->price,
            ];
        })->values()->toArray();

        $services = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => $s->subtotal,
        ])->values()->toArray();

        [$promotions, $promotionDiscount] = $this->recomputePromotions($order->items, $rtsMap);

        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');

        // Guest surcharge
        $guestSurchargeInfo  = null;
        $guestSurchargeTotal = 0;
        if ($product) {
            $guestConfig    = $product->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);
            $isSlotType     = (int) $product->styles === 1;
            $nights         = $isSlotType ? $this->countNights($order->items) : max(1, $order->items->count());
            $guestCount     = (int) $order->guest_count;
            $extraGuests    = max(0, $guestCount - $guestThreshold);
            $guestSurchargeTotal = $extraGuests * $guestFee * $nights;
            if ($guestFee > 0 && $extraGuests > 0) {
                $nightsLabel = (! $isSlotType && $nights > 1) ? " × {$nights} đêm" : '';
                $guestSurchargeInfo = [
                    'guest_count'    => $guestCount,
                    'threshold'      => $guestThreshold,
                    'extra_guests'   => $extraGuests,
                    'fee_per_person' => $guestFee,
                    'nights'         => $nights,
                    'total'          => $guestSurchargeTotal,
                    'label'          => "Phụ thu {$extraGuests} người (trên {$guestThreshold} người){$nightsLabel}",
                ];
            }
        }

        $depositPctDetail = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
        if ($depositPctDetail !== null && $depositPctDetail > 0 && $depositPctDetail < 100) {
            $realFinalDetail = (int) round((int) $order->full_amount * 100 / $depositPctDetail);
            $totalDiscount   = max(0, (int) $order->amount - $realFinalDetail);
        } else {
            $realFinalDetail = (int) $order->full_amount;
            $totalDiscount   = max(0, (int) $order->amount - (int) $order->full_amount);
        }

        $otherDiscount = max(0, $totalDiscount - $promotionDiscount);
        $slotsFinal    = max(0, $slotsTotal - $totalDiscount);

        $couponsInfo    = $this->buildCouponsInfo($order, $slotsTotal - $promotionDiscount);
        $couponDiscount = array_sum(array_column($couponsInfo, 'discount_amount'));
        $sysDelta       = max(0, $otherDiscount - $couponDiscount);

        $result = [
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
                'guest_count'    => (int) $order->guest_count,
            ],
            'room' => [
                'id'        => $product?->id,
                'slug'      => $product?->slug,
                'name'      => $product?->name,
                'thumbnail' => $this->getRoomThumbnail($product),
            ],
            'slots'           => $slots,
            'services'        => $services,
            'guest_surcharge' => $guestSurchargeInfo,
            'promotions'      => $promotions,
            'system_discount' => $sysDelta > 0 ? ['discount_amount' => $sysDelta] : null,
            'coupons'         => $couponsInfo,

            'deposit' => $depositPctDetail !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPctDetail,
                'deposit_amount'   => (int) $order->full_amount,
                'remaining_amount' => max(0, $realFinalDetail - (int) $order->full_amount) + (int) ($order->extra_charge_amount ?? 0),
            ] : null,

            'summary' => [
                'slots_total'          => $slotsTotal,
                'promotion_discount'   => $promotionDiscount,
                'system_discount'      => $sysDelta,
                'coupon_discount'      => $couponDiscount,
                'discount_amount'      => $totalDiscount,
                'slots_final'          => $slotsFinal,
                'guest_surcharge'      => $guestSurchargeTotal,
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinalDetail,
                'final_amount'         => (int) $order->full_amount,
                'grand_total'          => $realFinalDetail + (int) ($order->extra_charge_amount ?? 0),
            ],
        ];

        $lockInfo = $this->buildLockInfo($order, $product);
        if ($lockInfo) {
            $result['lock_info'] = $lockInfo;
        }

        // Extra charge (phát sinh thêm sau khi đã thanh toán)
        if ($order->extra_charge_amount) {
            $isExpired    = $order->extra_charge_expired_at && now()->gt($order->extra_charge_expired_at);
            $isPaid       = ! is_null($order->extra_charge_paid_at);
            $result['extra_charge'] = [
                'amount'         => (int) $order->extra_charge_amount,
                'qr_code'        => ($isPaid || $isExpired) ? null : $order->extra_charge_qr_code,
                'payment_method' => $order->extra_charge_payment_method,
                'paid_at'        => $order->extra_charge_paid_at,
                'expired_at'     => $order->extra_charge_expired_at,
                'is_paid'        => $isPaid,
                'is_expired'     => $isExpired && ! $isPaid,
            ];
        }

        return $result;
    }

    private function recomputePromotions($items, $rtsMap): array
    {
        $calculator    = new PromotionCalculator();
        $applied       = [];
        $totalDiscount = 0;

        $mergeApplied = function (array &$applied, array $entries): void {
            foreach ($entries as $entry) {
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
        };

        $product = $items->first()?->product;
        if ($rtsMap->isEmpty() && $product) {
            $dateRtsMap = $product->roomTimeSlots
                ->whereNotNull('date')
                ->keyBy(fn ($rts) => $rts->date->format('Y-m-d'));

            foreach ($items as $item) {
                $date  = $item->checkin_date?->format('Y-m-d');
                $rts   = $date ? $dateRtsMap->get($date) : null;
                $price = (float) $item->price;

                $result = $calculator->calculateForDate($rts, $price, $date ?? '');
                $disc   = (int) ($price - $result['final_price']);
                $totalDiscount += $disc;
                $mergeApplied($applied, $result['applied']);
            }

            return [$applied, (int) $totalDiscount];
        }

        foreach ($items as $item) {
            $startTime = $item->checkin_date?->format('H:i:s');
            $rts       = $startTime ? $rtsMap->get($startTime) : null;
            $date      = $item->checkin_date?->format('Y-m-d');

            if (! $rts || ! $date || ! $rts->timeSlot) {
                continue;
            }

            $result        = $calculator->calculate($rts, $date);
            $totalDiscount += $result['promo_discount'];
            $mergeApplied($applied, $result['applied']);
        }

        return [$applied, $totalDiscount];
    }

    /**
     * Số ngày thực tế của đơn slot — đếm theo ngày (checkin_date) duy nhất
     * giữa các item, vì cùng 1 khung giờ có thể được đặt lặp lại ở nhiều ngày.
     */
    private function countNights($items): int
    {
        return max(1, $items->pluck('checkin_date')->filter()->map(
            fn ($d) => $d->format('Y-m-d')
        )->unique()->count());
    }

    /**
     * Tính lại coupon discount từ coupon_codes/coupon_code lưu trên đơn.
     * Dùng khi guest_count thay đổi để không mất coupon cũ.
     */
    private function recomputeCouponDiscount(Order $order, float $baseAmount): int
    {
        $codes = $order->coupon_codes
            ?? ($order->coupon_code ? [$order->coupon_code] : []);

        return $this->recomputeCouponDiscountFromCodes(
            is_array($codes) ? $codes : [],
            $baseAmount
        );
    }

    /**
     * Tính discount của danh sách code trên $baseAmount (không validate lại quyền,
     * vì đây là recalc cho đơn đã tạo — coupon đã được validate từ trước).
     */
    private function recomputeCouponDiscountFromCodes(array $codes, float $baseAmount): int
    {
        if (empty($codes)) {
            return 0;
        }

        $coupons = Coupon::whereIn('code', $codes)->get()->keyBy('code');

        // Sắp xếp theo thứ tự code gốc, giữ % trước fixed
        $sorted = collect($codes)
            ->map(fn ($c) => $coupons->get($c))
            ->filter()
            ->sortByDesc(fn ($c) => $c->type === 'percentage' ? 1 : 0)
            ->values();

        $total     = 0;
        $remaining = $baseAmount;

        foreach ($sorted as $coupon) {
            if ($remaining <= 0) break;
            $disc       = (int) $coupon->calculateDiscount($remaining);
            $remaining -= $disc;
            $total     += $disc;
        }

        return $total;
    }

    /**
     * Build coupon info array để trả về trong response.
     * $baseAmount = số tiền sau promo, trước coupon.
     */
    private function buildCouponsInfo(Order $order, float $baseAmount): array
    {
        $codes = $order->coupon_codes
            ?? ($order->coupon_code ? [$order->coupon_code] : []);

        if (empty($codes) || ! is_array($codes)) {
            return [];
        }

        $coupons = Coupon::whereIn('code', $codes)->get()->keyBy('code');

        $sorted = collect($codes)
            ->map(fn ($c) => $coupons->get($c))
            ->filter()
            ->sortByDesc(fn ($c) => $c->type === 'percentage' ? 1 : 0)
            ->values();

        $result    = [];
        $remaining = $baseAmount;

        foreach ($sorted as $coupon) {
            if ($remaining <= 0) break;
            $disc       = (int) $coupon->calculateDiscount($remaining);
            $remaining -= $disc;

            $result[] = [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'type'            => $coupon->type,
                'value'           => $coupon->value,
                'discount_amount' => $disc,
            ];
        }

        return $result;
    }

    /**
     * Giải phóng lượt dùng của tất cả coupon đang áp trên đơn.
     */
    private function releaseCouponUsage(Order $order): void
    {
        $codes = $order->coupon_codes
            ?? ($order->coupon_code ? [$order->coupon_code] : []);

        if (empty($codes) || ! is_array($codes)) {
            return;
        }

        Coupon::whereIn('code', $codes)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }

    /**
     * Validate + apply nhiều coupon khi cập nhật đơn.
     * Trả về [totalDiscount, appliedList (có _model)].
     */
    private function applyAndValidateCoupons(
        array $codes,
        float $baseAmount,
        ?\Modules\Product\App\Models\Product $product,
        \App\Models\Customer $customer
    ): array {
        if (empty($codes)) {
            return [0, []];
        }

        $coupons = Coupon::whereIn('code', $codes)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->get()
            ->keyBy('code');

        $validated = [];
        foreach ($codes as $index => $code) {
            $coupon = $coupons->get($code);
            $field  = "coupon_codes.{$index}";

            if (! $coupon) {
                throw ValidationException::withMessages([
                    $field => ["Mã \"{$code}\" không tồn tại hoặc đã hết hạn."],
                ]);
            }

            if ($coupon->customer_id !== null && $coupon->customer_id !== $customer->id) {
                throw ValidationException::withMessages([
                    $field => ["Mã \"{$code}\" không thuộc về tài khoản của bạn."],
                ]);
            }

            if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
                throw ValidationException::withMessages([
                    $field => ["Mã \"{$code}\" đã hết lượt sử dụng."],
                ]);
            }

            if ($product) {
                $applicable = match ($coupon->apply_type) {
                    'all_rooms'     => true,
                    'specific_room' => $coupon->room_id === $product->id,
                    default         => true, // specific_slot: cho qua khi update
                };

                if (! $applicable) {
                    throw ValidationException::withMessages([
                        $field => ["Mã \"{$code}\" không áp dụng cho phòng này."],
                    ]);
                }
            }

            $validated[] = $coupon;
        }

        // Sắp xếp: % trước, fixed sau
        usort($validated, fn ($a, $b) => ($b->type === 'percentage' ? 1 : 0) - ($a->type === 'percentage' ? 1 : 0));

        $total     = 0;
        $applied   = [];
        $remaining = $baseAmount;

        foreach ($validated as $coupon) {
            if ($remaining <= 0) break;
            $disc       = (int) $coupon->calculateDiscount($remaining);
            $remaining -= $disc;
            $total     += $disc;

            $applied[] = [
                'code'            => $coupon->code,
                'name'            => $coupon->name,
                'type'            => $coupon->type,
                'value'           => $coupon->value,
                'discount_amount' => $disc,
                '_model'          => $coupon,
            ];
        }

        return [(int) $total, $applied];
    }

    private function buildLockInfo(Order $order, ?\Modules\Product\App\Models\Product $product): ?array
    {
        if (! $product || ! in_array($order->status, ['paid', 'deposit'])) {
            return null;
        }

        $checkinDate = $order->items->where('extra_fee', 0)->first()?->checkin_date;

        // Case 1: Mật khẩu thủ công (gate_password / room_password)
        $manualPwd = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($product, $checkinDate);
        if ($manualPwd) {
            return [
                'type'          => 'manual',
                'gate_password' => $manualPwd->gate_password,
                'room_password' => $manualPwd->room_password,
            ];
        }

        // Case 2: TTLock — chi nhánh có tài khoản TTLock + product có lock_id
        if ($product->lock_id && \App\Services\TTLockService::forCategory($order->category_id)) {
            return [
                'type'       => 'ttlock',
                'can_unlock' => true,
            ];
        }

        return null;
    }

    private function getRoomThumbnail(?\Modules\Product\App\Models\Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $media = $product->getFirstMedia('Ảnh bìa')
              ?? $product->getFirstMedia('Ảnh chính')
              ?? $product->getFirstMedia();

        return $media?->getUrl();
    }
}
