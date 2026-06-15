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
use PayOS\PayOS;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(Request $request): JsonResponse
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

        return response()->json($this->buildDetail($order));
    }

    // ─────────────────────────────────────────────

    /**
     * PATCH /api/orders/{order_code}
     * Cập nhật thông tin người mua và/hoặc dịch vụ bổ sung.
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
        ]);

        $updates             = [];
        $originalFullAmount  = (int) $order->full_amount;

        foreach (['guest_count', 'note_for_admin'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $request->input($field);
            }
        }

        // Đồng bộ guest_count xuống từng OrderItem + tính lại phụ thu khách nếu có cấu hình
        if ($request->has('guest_count')) {
            $newGuestCount = (int) $request->input('guest_count');
            $order->items()->update(['guest_count' => $newGuestCount]);

            $productId = $order->items->first()?->product_id;
            // Dùng luôn product đã eager-load (có roomType, roomTimeSlots, promotions)
            $guestRoom = $order->items->first()?->product;
            $guestConfig    = $guestRoom?->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);

            if ($guestFee > 0) {
                $itemsSum       = (int) $order->items->sum('price');
                $oldServicesSum = (int) $order->services->sum('subtotal');
                $oldSurcharge   = max(0, (int) $order->amount - $itemsSum - $oldServicesSum);
                // Slot (theo_gio): phụ thu tính 1 lần duy nhất, không nhân theo số slot
                $isSlotType   = $guestRoom?->roomType?->slug === 'theo_gio';
                $nights         = $isSlotType ? 1 : max(1, $order->items->count());
                $newSurcharge   = max(0, $newGuestCount - $guestThreshold) * $guestFee * $nights;

                if ($newSurcharge !== $oldSurcharge) {
                    $newAmtWithSurcharge = max(0, (int) $order->amount - $oldSurcharge + $newSurcharge);
                    $updates['amount']   = $newAmtWithSurcharge;

                    // Tính lại promotion từ RTS hiện tại
                    $gcPromoRtsMap = collect();
                    if ($guestRoom) {
                        $gcPromoRtsMap = $guestRoom->roomTimeSlots
                            ->whereNull('date')
                            ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
                    }
                    [, $gcRecomputedPromo] = $this->recomputePromotions($order->items, $gcPromoRtsMap);

                    $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
                    if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                        $origFinal          = (int) round((int) $order->full_amount * 100 / $depositPct);
                        $origTotalDiscount  = max(0, (int) $order->amount - $origFinal);
                        $gcCouponDiscount   = max(0, $origTotalDiscount - $gcRecomputedPromo);
                        $gcTotalDiscount    = $gcRecomputedPromo + $gcCouponDiscount;
                        $updates['full_amount'] = (int) ceil(max(0, $newAmtWithSurcharge - $gcTotalDiscount) * $depositPct / 100);
                    } else {
                        $origTotalDiscount  = max(0, (int) $order->amount - (int) $order->full_amount);
                        $gcCouponDiscount   = max(0, $origTotalDiscount - $gcRecomputedPromo);
                        $gcTotalDiscount    = $gcRecomputedPromo + $gcCouponDiscount;
                        $updates['full_amount'] = max(0, $newAmtWithSurcharge - $gcTotalDiscount);
                    }

                    Log::info('order.update guest-surcharge', [
                        'order_code'          => $order->order_code,
                        'db_amount'           => (int) $order->amount,
                        'db_full_amount'      => (int) $order->full_amount,
                        'items_sum'           => $itemsSum,
                        'old_services_sum'    => $oldServicesSum,
                        'old_surcharge'       => $oldSurcharge,
                        'new_surcharge'       => $newSurcharge,
                        'new_amt_w_surcharge' => $newAmtWithSurcharge,
                        'recomputed_promo'    => $gcRecomputedPromo,
                        'orig_total_discount' => $origTotalDiscount,
                        'coupon_discount'     => $gcCouponDiscount,
                        'total_discount'      => $gcTotalDiscount,
                        'new_full_amount'     => $updates['full_amount'],
                    ]);

                    // Giá thay đổi → link PayOS cũ không còn hợp lệ
                    if ($order->checkout_url) {
                        $updates['checkout_url'] = null;
                        $updates['expired_at']   = null;
                    }
                }
            }
        }

        // Thay thế toàn bộ services nếu key được gửi lên
        $servicesResult = null;
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

            // Lưu tổng dịch vụ cũ TRƯỚC khi xoá để tính lại giá phòng (giữ phụ thu, không dùng items->sum('price'))
            $oldServicesTotal = (int) $order->services->sum('subtotal');

            // Xoá cũ, thêm mới
            $order->services()->delete();
            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            // Tính lại amount / full_amount
            // Nếu guest_count cũng vừa thay đổi thì dùng amount đã cập nhật phụ thu, không dùng DB cũ
            $currentAmountBase = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;
            $roomBaseAmount    = max(0, $currentAmountBase - $oldServicesTotal);
            $newSubtotal    = $roomBaseAmount + $addedTotal;

            // Tính lại promotion discount từ RTS hiện tại (items không đổi khi update services/guest)
            $promoRtsMap = collect();
            $promoProduct = $order->items->first()?->product;
            if ($promoProduct) {
                $promoRtsMap = $promoProduct->roomTimeSlots
                    ->whereNull('date')
                    ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
            }
            [, $recomputedPromoDiscount] = $this->recomputePromotions($order->items, $promoRtsMap);

            // Xử lý đơn cọc và đơn thường riêng biệt:
            // - Đơn thường: full_amount = finalAmount → discount = amount - full_amount
            // - Đơn cọc:   full_amount = deposit portion → phải reconstruct finalAmount thật trước
            $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
            if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                $origFinalAmount    = (int) round((int) $order->full_amount * 100 / $depositPct);
                $origTotalDiscount  = max(0, (int) $order->amount - $origFinalAmount);
                // Tách coupon = tổng discount cũ - promotion cũ (promotion cũ ≈ promotion hiện tại vì items không đổi)
                $couponDiscount     = max(0, $origTotalDiscount - $recomputedPromoDiscount);
                $totalNewDiscount   = $recomputedPromoDiscount + $couponDiscount;
                $newRealFinal       = max(0, $newSubtotal - $totalNewDiscount);
                $newFullAmount      = (int) ceil($newRealFinal * $depositPct / 100);
            } else {
                $origTotalDiscount  = max(0, (int) $order->amount - (int) $order->full_amount);
                $couponDiscount     = max(0, $origTotalDiscount - $recomputedPromoDiscount);
                $totalNewDiscount   = $recomputedPromoDiscount + $couponDiscount;
                $newRealFinal       = max(0, $newSubtotal - $totalNewDiscount);
                $newFullAmount      = $newRealFinal;
            }

            Log::info('order.update services-recalc', [
                'order_code'             => $order->order_code,
                'db_amount'              => (int) $order->amount,
                'db_full_amount'         => (int) $order->full_amount,
                'deposit_pct'            => $depositPct,
                'current_amt_base'       => $currentAmountBase,
                'old_services'           => $oldServicesTotal,
                'added_total'            => $addedTotal,
                'new_subtotal'           => $newSubtotal,
                'recomputed_promo'       => $recomputedPromoDiscount,
                'orig_total_discount'    => $origTotalDiscount,
                'coupon_discount'        => $couponDiscount,
                'total_new_discount'     => $totalNewDiscount,
                'new_real_final'         => $newRealFinal,
                'new_full_amount'        => $newFullAmount,
            ]);

            $updates['amount']      = $newSubtotal;
            $updates['full_amount'] = $newFullAmount;

            // Link PayOS cũ tạo với giá cũ → vô hiệu hoá nếu giá thay đổi
            if ($newFullAmount !== (int) $order->full_amount && $order->checkout_url) {
                $updates['checkout_url'] = null;
                $updates['expired_at']   = null;
            }

            $servicesResult = $servicesData;
        }

        if (! empty($updates)) {
            $order->update($updates);
            $order->refresh();
        }

        // Tạo lại link PayOS nếu giá vừa thay đổi hoặc chưa có link
        $priceChanged = (int) $order->full_amount !== $originalFullAmount;
        Log::info('order.update payos-check', [
            'order_code'     => $order->order_code,
            'payment_method' => $order->payment_method,
            'price_changed'  => $priceChanged,
            'original_amt'   => $originalFullAmount,
            'new_amt'        => (int) $order->full_amount,
            'has_url'        => (bool) $order->checkout_url,
        ]);
        if (
            $order->payment_method === 'PayOS' &&
            ($priceChanged || ! $order->checkout_url) &&
            (int) $order->full_amount >= 2000
        ) {
            $itemName = $order->items->first()?->name ?? 'Đặt phòng';
            $this->buildPayOSLink($order, $itemName);
            $order->refresh();
        }

        // Load thêm relationships cần cho response đầy đủ
        $order->load([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
        ]);

        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        // ── Slots ──
        $slots = $order->items->map(fn ($item) => [
            'date'  => $item->checkin_date?->format('Y-m-d'),
            'price' => (int) $item->price,
        ])->values()->toArray();

        // ── Services (dùng từ DB sau khi đã refresh) ──
        $servicesResult = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => (int) $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => (int) $s->subtotal,
        ])->values()->toArray();
        $servicesTotal = array_sum(array_column($servicesResult, 'subtotal'));

        // ── Guest surcharge ──
        $guestSurchargeInfo  = null;
        $guestSurchargeTotal = 0;
        if ($product) {
            $guestConfig    = $product->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);
            // Slot (theo_gio): phụ thu tính 1 lần duy nhất, không nhân theo số slot
            $isSlotType  = $product->roomType?->slug === 'theo_gio';
            $nights         = $isSlotType ? 1 : max(1, $order->items->count());
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

        // ── Promotions (recompute từ config phòng hiện tại) ──
        $rtsMap = collect();
        if ($product) {
            $rtsMap = $product->roomTimeSlots
                ->whereNull('date')
                ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
        }
        [$promotions, $promotionDiscount] = $this->recomputePromotions($order->items, $rtsMap);

        // ── Tính discount ──
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

        // Phân biệt coupon vs system_discount dựa vào coupon_code lưu trên đơn
        $couponInfo     = null;
        $couponDiscount = 0;
        $sysDelta       = $otherDiscount;

        if ($order->coupon_code) {
            $coupon = \Modules\Promotion\App\Models\Coupon::where('code', $order->coupon_code)->first();
            if ($coupon) {
                $couponDiscount = min($otherDiscount, (int) $coupon->calculateDiscount($slotsTotal - $promotionDiscount));
                $couponInfo = [
                    'code'            => $coupon->code,
                    'name'            => $coupon->name,
                    'type'            => $coupon->type,
                    'value'           => $coupon->value,
                    'discount_amount' => $couponDiscount,
                ];
                $sysDelta = max(0, $otherDiscount - $couponDiscount);
            }
        }

        return response()->json([
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'checkout_url'   => $order->checkout_url,
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
            'coupon'          => $couponInfo,
            'deposit'         => $depositPctResp !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPctResp,
                'deposit_amount'   => $newFullAmt,
                'remaining_amount' => max(0, $realFinalAmount - $newFullAmt),
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
     * POST /api/orders/{order_code}/retry-payment
     * Tạo lại link PayOS khi link cũ hết hạn hoặc bị huỷ.
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
            'order_code'   => $order->order_code,
            'status'       => $order->status,
            'checkout_url' => $order->checkout_url,
            'qr_code'      => $order->qr_code,
            'expired_at'   => $order->expired_at,
        ]);
    }

    /**
     * POST /api/orders/{order_code}/remaining-payment
     * Tạo link PayOS để thanh toán phần còn lại sau khi đã đặt cọc.
     */
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

        // Đơn cọc: full_amount = tiền cọc đã thanh toán; reconstruct tổng thực để tính remaining
        $depositPct  = (int) $order->deposit_percent;
        $depositPaid = (int) $order->full_amount;
        $realTotal   = $depositPct > 0 ? (int) round($depositPaid * 100 / $depositPct) : $depositPaid;
        $remaining   = $realTotal - $depositPaid;

        // Đã có link còn dùng được → trả về luôn
        if ($order->remaining_checkout_url && $order->remaining_payos_code) {
            return response()->json([
                'order_code'   => $order->order_code,
                'checkout_url' => $order->remaining_checkout_url,
                'amount'       => $remaining,
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
            ]);

            return response()->json([
                'order_code'   => $order->order_code,
                'checkout_url' => $checkoutUrl,
                'qr_code'      => $qrCode,
                'amount'       => $remaining,
                'expired_at'   => $expiredAt->toIso8601String(),
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
     * Kiểm tra trạng thái PayOS và cập nhật đơn hàng.
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

        // Không cần gọi PayOS nếu đã xác định rõ
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

            // Đơn cọc đang chờ thanh toán còn lại → query remaining_payos_code
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
                        $order->update([
                            'status'                   => 'paid',
                            'remaining_paid_at'        => now(),
                            'remaining_payment_method' => 'payos',
                        ]);
                        // Cấp mã cổng tự động sau khi khách thanh toán phần còn lại
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
                'checkout_url' => $order->checkout_url,
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

            // Huỷ link cũ trên PayOS (dùng current_payos_code nếu đã có, fallback về order_code)
            $oldPayosCode = $order->current_payos_code ?? (int) $order->order_code;
            try {
                $payOS->cancelPaymentLink((int) $oldPayosCode);
                Log::info('buildPayOSLink: cancel ok', ['order_code' => $order->order_code, 'payos_code' => $oldPayosCode]);
            } catch (\Throwable $e) {
                Log::info('buildPayOSLink: cancel skipped', [
                    'order_code' => $order->order_code,
                    'payos_code' => $oldPayosCode,
                    'reason'     => $e->getMessage(),
                ]);
            }

            // PayOS không cho tạo lại với cùng orderCode → dùng unique code mới mỗi lần
            $newPayosCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));

            Log::info('buildPayOSLink: creating', [
                'order_code'     => $order->order_code,
                'new_payos_code' => $newPayosCode,
                'amount'         => (int) $order->full_amount,
            ]);

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

            Log::info('buildPayOSLink: result', [
                'order_code'     => $order->order_code,
                'new_payos_code' => $newPayosCode,
                'checkout_url'   => $checkoutUrl,
                'has_qr'         => (bool) $qrCode,
            ]);

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

        // Tên phòng: ưu tiên lấy từ product, fallback từ phần đầu của item.name
        $roomName = $firstItem?->product?->name
            ?? ($firstItem?->name ? explode(' - ', $firstItem->name, 2)[0] : null);

        return [
            'order_code'   => $order->order_code,
            'created_at'   => $order->created_at->format('Y-m-d H:i:s'),
            'status'       => $order->status,
            'room_id'        => $firstItem?->product?->id,
            'room_slug'      => $firstItem?->product?->slug,
            'room_name'      => $roomName,
            'room_thumbnail' => $this->getRoomThumbnail($firstItem?->product),
            'checkin'      => $firstItem?->checkin_date?->format('Y-m-d H:i'),
            'checkout'     => $lastItem?->checkout_date?->format('Y-m-d H:i'),
            'final_amount' => (int) $order->full_amount,
        ];
    }

    private function buildDetail(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        // Map RoomTimeSlot theo start_time để tra timeslot_id
        $rtsMap = collect();
        if ($product) {
            $rtsMap = $product->roomTimeSlots
                ->whereNull('date')
                ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
        }

        // ── Slots ──
        $slots = $order->items->map(function ($item) use ($rtsMap) {
            $startTime = $item->checkin_date?->format('H:i:s');
            $rts       = $startTime ? $rtsMap->get($startTime) : null;

            // Label nằm sau "RoomName - " trong item.name
            $nameParts = $item->name ? explode(' - ', $item->name, 2) : [];
            $label     = count($nameParts) > 1 ? $nameParts[1] : null;

            return [
                'timeslot_id' => $rts?->timeslot_id,
                'date'        => $item->checkin_date?->format('Y-m-d'),
                'label'       => $label,
                'price'       => (int) $item->price,
            ];
        })->values()->toArray();

        // ── Services ──
        $services = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => $s->subtotal,
        ])->values()->toArray();

        // ── Promotions (recompute từ config phòng hiện tại) ──
        [$promotions, $promotionDiscount] = $this->recomputePromotions($order->items, $rtsMap);

        // ── Summary ──
        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');

        // amount = subtotal (slots + services + phụ thu, trước discount)
        // full_amount = với đơn cọc: tiền cọc; với đơn thường: số tiền sau discount
        $depositPctDetail = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
        if ($depositPctDetail !== null && $depositPctDetail > 0 && $depositPctDetail < 100) {
            $realFinalDetail = (int) round((int) $order->full_amount * 100 / $depositPctDetail);
            $totalDiscount   = max(0, (int) $order->amount - $realFinalDetail);
        } else {
            $realFinalDetail = (int) $order->full_amount;
            $totalDiscount   = max(0, (int) $order->amount - (int) $order->full_amount);
        }

        // Phần discount không phải promotion: có thể là bulk/full_booking và/hoặc coupon
        $otherDiscount = max(0, $totalDiscount - $promotionDiscount);

        $slotsFinal = max(0, $slotsTotal - $totalDiscount);

        return [
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
            ],
            'room' => [
                'id'        => $product?->id,
                'slug'      => $product?->slug,
                'name'      => $product?->name,
                'thumbnail' => $this->getRoomThumbnail($product),
            ],
            'slots'    => $slots,
            'services' => $services,

            // Recomputed từ config phòng hiện tại
            'promotions' => $promotions,

            // Không lưu khi tạo đơn → không thể phục hồi chi tiết
            'system_discount' => $otherDiscount > 0 ? ['discount_amount' => $otherDiscount] : null,
            'coupon'          => null,

            'deposit' => $depositPctDetail !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPctDetail,
                'deposit_amount'   => (int) $order->full_amount,
                'remaining_amount' => max(0, $realFinalDetail - (int) $order->full_amount),
            ] : null,

            'summary' => [
                'slots_total'          => $slotsTotal,
                'promotion_discount'   => $promotionDiscount,
                'system_discount'      => $otherDiscount,
                'coupon_discount'      => 0,
                'discount_amount'      => $totalDiscount,
                'slots_final'          => $slotsFinal,
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinalDetail,
                'final_amount'         => (int) $order->full_amount,
            ],
        ];
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

        // Daily rooms: slot-based rtsMap (null-date RTS) sẽ rỗng → dùng date-keyed RTS
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

        // Slot rooms: tra cứu RTS theo start_time
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
