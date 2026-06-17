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
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use App\Services\PromotionCalculator;
use Modules\Promotion\App\Models\Coupon;
use PayOS\PayOS;

class GuestBookingController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════
    // POST /api/guest/orders
    // Đặt phòng cho khách không đăng nhập (slot hoặc daily)
    // ══════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        // ── 1. Validate ──────────────────────────────────────────────────────
        $baseRules = [
            'type'                    => 'required|in:slot,daily',
            'room_id'                 => 'required|string',
            'buyer_name'              => 'required|string|max:100',
            'buyer_phone'             => 'required|string|max:20',
            'guest_count'             => 'required|integer|min:1',
            'payment_method'          => 'sometimes|in:PayOS,cash',
            'payment_type'            => 'sometimes|in:full,deposit',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'cccd_front'              => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ];

        if ($request->input('type') === 'slot') {
            $baseRules['date']                = 'sometimes|date_format:Y-m-d|after_or_equal:today';
            $baseRules['slots']               = 'required|array|min:1';
            $baseRules['slots.*.timeslot_id'] = 'required|integer';
            $baseRules['slots.*.date']        = 'sometimes|date_format:Y-m-d|after_or_equal:today';
        } else {
            $baseRules['checkin_date']  = 'required|date_format:Y-m-d|after_or_equal:today';
            $baseRules['checkout_date'] = 'required|date_format:Y-m-d|after:checkin_date';
        }

        $request->validate($baseRules);

        $couponInput = $request->input('coupon_codes');
        if (empty($couponInput)) {
            $raw = $request->input('coupon_code');
            if ($raw !== null) {
                $couponInput = is_array($raw) ? $raw : [$raw];
            }
        }
        $couponCodes = array_values(array_unique(array_map('strtoupper', array_filter((array) ($couponInput ?? [])))));

        $buyerName = trim($request->input('buyer_name'));
        $buyerPhone = trim($request->input('buyer_phone'));

        // ── CCCD upload + QR scan (bắt buộc cho guest) ───────────────────────
        $cccdFront = $request->file('cccd_front')->store('cccd', 'public');
        $cccdBack  = $request->file('cccd_back')->store('cccd', 'public');

        $tempOrder = new Order(['cccd_front' => $cccdFront, 'cccd_back' => $cccdBack]);
        $cccdData  = app(CccdScannerService::class)->scanOrder($tempOrder);

        if (! $cccdData) {
            Storage::disk('public')->delete($cccdFront);
            Storage::disk('public')->delete($cccdBack);

            return response()->json([
                'message' => 'Không đọc được QR trên ảnh CCCD. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.',
            ], 422);
        }

        // ── 2. Load phòng ─────────────────────────────────────────────────────
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

        // ── 3. Xây dựng items ─────────────────────────────────────────────────
        $rtsCollection = collect();
        $slotSummary   = [];

        if ($request->input('type') === 'slot') {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildSlotItems($request, $room);
        } else {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildDailyItems($request, $room);
        }

        // ── 4. Dịch vụ bổ sung ───────────────────────────────────────────────
        [$servicesTotal, $servicesData] = $this->buildServices($request, $room);

        // ── 5. Phụ thu số lượng người ─────────────────────────────────────────
        [$guestSurcharge, $guestSurchargeInfo] = $this->buildGuestSurcharge($request, $room, $slotSummary);

        $subtotal = $basePrice + $servicesTotal + $guestSurcharge;

        // ── 6. Áp dụng discount ───────────────────────────────────────────────
        $appliedPromotions     = [];
        $promotionDiscount     = 0;
        $appliedSystemDiscount = null;
        $systemDiscount        = 0;
        $appliedCoupons        = [];
        $couponDiscount        = 0;

        $hasFullBooking = ! empty($slotSummary) && $this->checkFullDayBooking($slotSummary, $room);

        if ($hasFullBooking) {
            [$systemDiscount, $appliedSystemDiscount] = $this->applyFullBookingDiscount($basePrice, $room);

            $couponBase = $basePrice - $systemDiscount;
            if (! empty($couponCodes)) {
                [$couponDiscount, $appliedCoupons] = $this->applyGuestCoupons($couponCodes, $couponBase, $room, $rtsCollection);
            }
        } else {
            if ($rtsCollection->isNotEmpty()) {
                if ($request->input('type') === 'daily') {
                    [$promotionDiscount, $appliedPromotions] = $this->applyDailyPromotions($rtsCollection, $slotSummary);
                } else {
                    [$promotionDiscount, $appliedPromotions] = $this->applyPromotions($rtsCollection, $slotSummary);
                }
            }

            if (! empty($slotSummary)) {
                [$systemDiscount, $appliedSystemDiscount] = $this->applyBulkDiscount(
                    count($slotSummary),
                    $room,
                    $basePrice - $promotionDiscount
                );
            }

            $couponBase = $basePrice - $promotionDiscount - $systemDiscount;
            if (! empty($couponCodes)) {
                [$couponDiscount, $appliedCoupons] = $this->applyGuestCoupons($couponCodes, $couponBase, $room, $rtsCollection);
            }
        }

        $discountAmount = $promotionDiscount + $systemDiscount + $couponDiscount;
        $slotFinalPrice = max(0, $basePrice - $discountAmount);
        $finalAmount    = $slotFinalPrice + $servicesTotal + $guestSurcharge;

        // ── Deposit (chỉ daily) ───────────────────────────────────────────────
        $amountDue   = $finalAmount;
        $depositInfo = null;

        if ($request->input('type') === 'daily') {
            $depositMin  = (int) ($room->deposit_min_nights  ?? 0);
            $depositPct  = (int) ($room->deposit_multi_night ?? 50);
            $paymentType = $request->input('payment_type', 'full');

            if ($paymentType === 'deposit') {
                $nights = count($slotSummary);
                if ($depositMin > 0 && $nights >= $depositMin && $depositPct < 100) {
                    $amountDue   = (int) ceil($finalAmount * $depositPct / 100);
                    $depositInfo = [
                        'type'             => 'deposit',
                        'percentage'       => $depositPct,
                        'deposit_amount'   => $amountDue,
                        'remaining_amount' => $finalAmount - $amountDue,
                    ];
                } else {
                    throw ValidationException::withMessages([
                        'payment_type' => [
                            'Đặt cọc không áp dụng' . ($depositMin > 0 ? " (cần tối thiểu {$depositMin} đêm)" : '') . '.',
                        ],
                    ]);
                }
            }
        }

        $category      = $room->categories()->first();
        $paymentMethod = $request->input('payment_method', 'PayOS');

        $depositPercentToSave = $depositInfo !== null ? (int) ($depositInfo['percentage']) : null;
        $appliedCouponCodes   = collect($appliedCoupons)->pluck('code')->values()->all();

        // ── 7. Tạo đơn trong transaction ─────────────────────────────────────
        $order = DB::transaction(function () use (
            $room, $amountDue, $finalAmount, $subtotal, $buyerName, $buyerPhone,
            $cccdFront, $cccdBack, $cccdData, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave
        ) {
            Product::where('id', $room->id)->lockForUpdate()->first();

            foreach ($itemsData as $itemData) {
                if (empty($itemData['checkin_date'])) {
                    continue;
                }
                $conflict = OrderItem::where('product_id', $room->id)
                    ->whereNotNull('checkin_date')
                    ->whereNotNull('checkout_date')
                    ->where('checkin_date', '<', $itemData['checkout_date'])
                    ->where('checkout_date', '>', $itemData['checkin_date'])
                    ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'slots' => ['Khung giờ vừa được người khác đặt. Vui lòng chọn khung giờ khác.'],
                    ]);
                }
            }

            $firstCode = $appliedCouponCodes[0] ?? null;

            $order = Order::create([
                'amount'          => $subtotal,
                'full_amount'     => $amountDue,
                'deposit_percent' => $depositPercentToSave,
                'coupon_code'     => $firstCode,
                'coupon_codes'    => $appliedCouponCodes ?: null,
                'description'     => 'Đặt phòng - ' . $room->name,
                'buyer_name'      => $buyerName,
                'buyer_phone'     => $buyerPhone,
                'cccd_front'      => $cccdFront,
                'cccd_back'       => $cccdBack,
                'cccd_data'       => $cccdData,
                'payment_method'  => $paymentMethod,
                'status'          => 'pending',
                'guest_count'     => $request->guest_count,
                'category_id'     => $category?->id,
                'customer_id'     => null,
            ]);

            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            foreach ($appliedCoupons as $couponInfo) {
                if (isset($couponInfo['_model'])) {
                    $couponInfo['_model']->incrementUsage();
                }
            }

            return $order;
        });

        // ── 8. Tạo link PayOS ────────────────────────────────────────────────
        if ($paymentMethod === 'PayOS' && $amountDue >= 2000) {
            $this->createPayOSLink($order, $summaryName);
        }

        // ── 9. Realtime ───────────────────────────────────────────────────────
        $realtimeService = app(\App\Services\SlotRealtimeService::class);

        if ($request->input('type') === 'daily' && ! empty($slotSummary)) {
            $realtimeService->broadcastDailyBooked($room->id, $request->input('checkin_date'), $request->input('checkout_date'));
        } elseif (! empty($slotSummary)) {
            $byDate = collect($slotSummary)->groupBy('date');
            foreach ($byDate as $date => $slots) {
                $realtimeService->broadcastBooked(
                    $room->id,
                    $date,
                    $slots->pluck('timeslot_id')->values()->toArray()
                );
            }
        }

        $order->refresh();

        $couponsForResponse = collect($appliedCoupons)->map(fn ($c) => collect($c)->except('_model')->all())->values()->all();

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
                'cccd_front'     => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'      => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'      => $order->cccd_data,
            ],
            'room' => [
                'id'   => $room->id,
                'name' => $room->name,
            ],
            'slots'            => $slotSummary,
            'services'         => $servicesData,
            'guest_surcharge'  => $guestSurchargeInfo,
            'promotions'       => $appliedPromotions,
            'system_discount'  => $appliedSystemDiscount,
            'coupons'          => $couponsForResponse,
            'deposit'          => $depositInfo,
            'summary' => [
                'slots_total'          => $basePrice,
                'promotion_discount'   => $promotionDiscount,
                'system_discount'      => $systemDiscount,
                'coupon_discount'      => $couponDiscount,
                'discount_amount'      => $discountAmount,
                'slots_final'          => $slotFinalPrice,
                'guest_surcharge'      => $guestSurcharge,
                'services_total'       => $servicesTotal,
                'total_after_discount' => (int) $finalAmount,
                'final_amount'         => (int) $order->full_amount,
            ],
        ], 201);
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/guest/orders/{order_code}
    // Cập nhật đơn — xác thực qua buyer_phone
    // ══════════════════════════════════════════════════════════════════════

    public function update(Request $request, string $orderCode): JsonResponse
    {
        $request->validate([
            'buyer_phone'             => 'required|string|max:20',
            'guest_count'             => 'sometimes|integer|min:1|max:50',
            'note_for_admin'          => 'sometimes|nullable|string|max:500',
            'cccd_front'              => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'services'                => 'sometimes|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
        ]);

        $order = Order::with([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
        ])
            ->where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', $request->input('buyer_phone'))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể cập nhật khi đơn đang ở trạng thái pending.'], 422);
        }

        $updates            = [];
        $originalFullAmount = (int) $order->full_amount;

        if ($request->has('note_for_admin')) {
            $updates['note_for_admin'] = $request->input('note_for_admin');
        }

        // ── CCCD upload + QR scan ─────────────────────────────────────────────
        if ($request->hasFile('cccd_front') || $request->hasFile('cccd_back')) {
            $newFront = null;
            $newBack  = null;

            if ($request->hasFile('cccd_front')) {
                $newFront = $request->file('cccd_front')->store('cccd', 'public');
            }
            if ($request->hasFile('cccd_back')) {
                $newBack = $request->file('cccd_back')->store('cccd', 'public');
            }

            $tempOrder = new Order([
                'cccd_front' => $newFront ?? $order->cccd_front,
                'cccd_back'  => $newBack  ?? $order->cccd_back,
            ]);
            $cccdData = app(CccdScannerService::class)->scanOrder($tempOrder);

            if (! $cccdData) {
                if ($newFront) Storage::disk('public')->delete($newFront);
                if ($newBack)  Storage::disk('public')->delete($newBack);

                return response()->json([
                    'message' => 'Không đọc được QR trên ảnh CCCD. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.',
                ], 422);
            }

            // QR hợp lệ — xoá file cũ nếu có
            if ($newFront && $order->cccd_front) {
                Storage::disk('public')->delete($order->cccd_front);
            }
            if ($newBack && $order->cccd_back) {
                Storage::disk('public')->delete($order->cccd_back);
            }

            if ($newFront) $updates['cccd_front'] = $newFront;
            if ($newBack)  $updates['cccd_back']  = $newBack;
            $updates['cccd_data'] = $cccdData;
        }

        // ── Phụ thu khách ─────────────────────────────────────────────────────
        if ($request->has('guest_count')) {
            $newGuestCount = (int) $request->input('guest_count');
            $order->items()->update(['guest_count' => $newGuestCount]);

            $guestRoom      = $order->items->first()?->product;
            $guestConfig    = $guestRoom?->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);
            $updates['guest_count'] = $newGuestCount;

            if ($guestFee > 0) {
                $itemsSum       = (int) $order->items->sum('price');
                $oldServicesSum = (int) $order->services->sum('subtotal');
                $oldSurcharge   = max(0, (int) $order->amount - $itemsSum - $oldServicesSum);
                $isSlotType     = $guestRoom?->roomType?->slug === 'theo_gio';
                $nights         = $isSlotType ? 1 : max(1, $order->items->count());
                $newSurcharge   = max(0, $newGuestCount - $guestThreshold) * $guestFee * $nights;

                if ($newSurcharge !== $oldSurcharge) {
                    $newAmtWithSurcharge = max(0, (int) $order->amount - $oldSurcharge + $newSurcharge);
                    $updates['amount']   = $newAmtWithSurcharge;

                    [$promoDiscount] = $this->recomputePromotionDiscount($order);
                    $couponDiscount  = $this->recomputeCouponDiscountFromCodes(
                        is_array($order->coupon_codes) ? $order->coupon_codes : [],
                        max(0, $newAmtWithSurcharge - $promoDiscount)
                    );
                    $totalDisc = $promoDiscount + $couponDiscount;

                    $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
                    if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                        $updates['full_amount'] = (int) ceil(max(0, $newAmtWithSurcharge - $totalDisc) * $depositPct / 100);
                    } else {
                        $updates['full_amount'] = max(0, $newAmtWithSurcharge - $totalDisc);
                    }

                    if ($order->checkout_url) {
                        $updates['checkout_url'] = null;
                        $updates['qr_code']      = null;
                        $updates['expired_at']   = null;
                    }
                }
            }
        }

        // ── Cập nhật services ─────────────────────────────────────────────────
        if ($request->has('services')) {
            $productId = $order->items->first()?->product_id;
            if (! $productId) {
                return response()->json(['message' => 'Đơn hàng không có phòng.'], 422);
            }

            $room = Product::where('id', $productId)->with('additionalServices')->first();
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

            $oldServicesTotal      = (int) $order->services->sum('subtotal');
            $currentAmountBase     = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;
            $roomBaseAmount        = max(0, $currentAmountBase - $oldServicesTotal);
            $newSubtotal           = $roomBaseAmount + $addedTotal;

            $order->services()->delete();
            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            [$promoDiscount] = $this->recomputePromotionDiscount($order);
            $couponCodes     = is_array($order->coupon_codes) ? $order->coupon_codes : ($order->coupon_code ? [$order->coupon_code] : []);
            $couponDiscount  = $this->recomputeCouponDiscountFromCodes($couponCodes, max(0, $newSubtotal - $promoDiscount));
            $totalDiscount   = $promoDiscount + $couponDiscount;

            $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
            if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                $newFullAmount = (int) ceil(max(0, $newSubtotal - $totalDiscount) * $depositPct / 100);
            } else {
                $newFullAmount = max(0, $newSubtotal - $totalDiscount);
            }

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
            $this->rebuildPayOSLink($order, $itemName);
            $order->refresh();
        }

        $order->load([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
        ]);

        return response()->json($this->buildOrderResponse($order));
    }

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/guest/orders?phone={phone}
    // Tra cứu đơn theo số điện thoại
    // ══════════════════════════════════════════════════════════════════════

    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $phone = trim($request->input('phone'));

        $orders = Order::with(['items.product.media', 'services'])
            ->where('buyer_phone', $phone)
            ->whereNull('customer_id')
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

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/guest/orders/{order_code}?phone={phone}
    // Xem chi tiết đơn (xác thực qua SĐT)
    // ══════════════════════════════════════════════════════════════════════

    public function show(Request $request, string $orderCode): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $order = Order::with([
            'items.product.media',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
        ])
            ->where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', trim($request->input('phone')))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        return response()->json($this->buildOrderResponse($order));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Private helpers
    // ══════════════════════════════════════════════════════════════════════

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

    private function buildGuestSurcharge(Request $request, Product $room, array $slotSummary): array
    {
        if (empty($slotSummary)) {
            return [0, null];
        }

        $type = $request->input('type');

        if ($type === 'slot' && $room->roomType?->slug !== 'theo_gio') {
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
        $nights      = $type === 'daily' ? count($slotSummary) : 1;
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

    private function applyPromotions(Collection $rtsCollection, array $slotSummary = []): array
    {
        $calculator  = new PromotionCalculator();
        $slotDateMap = collect($slotSummary)->pluck('date', 'timeslot_id');

        $totalDiscount = 0;
        $applied       = [];

        foreach ($rtsCollection as $rts) {
            $bookingDate = $slotDateMap->get($rts->timeslot_id);
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

    private function parseDiscountRule(float $amount, string $rule): float
    {
        if (str_contains($rule, '%')) {
            $pct = (float) str_replace('%', '', $rule);
            return $amount * ($pct / 100);
        }

        return (float) str_replace(['.', ','], '', $rule);
    }

    /**
     * Áp dụng coupon cho guest: chấp nhận mọi coupon active có code hợp lệ.
     */
    private function applyGuestCoupons(
        array $codes,
        float $orderAmount,
        Product $room,
        Collection $rtsCollection
    ): array {
        if (empty($codes)) {
            return [0, []];
        }

        $codes = array_values(array_unique(array_map('strtoupper', $codes)));

        $coupons = [];
        foreach ($codes as $index => $code) {
            $coupon = Coupon::where('code', $code)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
                ->first();

            $field = "coupon_codes.{$index}";

            if (! $coupon) {
                throw ValidationException::withMessages([
                    $field => ["Mã \"{$code}\" không tồn tại, đã hết hạn, hoặc không áp dụng cho đơn không đăng nhập."],
                ]);
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

            $coupons[] = $coupon;
        }

        usort($coupons, fn ($a, $b) => ($b->type === 'percentage' ? 1 : 0) - ($a->type === 'percentage' ? 1 : 0));

        $totalDiscount = 0;
        $applied       = [];
        $remaining     = $orderAmount;

        foreach ($coupons as $coupon) {
            if ($remaining <= 0) break;

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

    private function recomputePromotionDiscount(Order $order): array
    {
        $calculator    = new PromotionCalculator();
        $applied       = [];
        $totalDiscount = 0;

        $product = $order->items->first()?->product;
        if (! $product) {
            return [$applied, 0];
        }

        $rtsMap = $product->roomTimeSlots->whereNull('date')->keyBy(fn ($rts) => $rts->timeSlot?->start_time);

        if ($rtsMap->isEmpty()) {
            $dateRtsMap = $product->roomTimeSlots->whereNotNull('date')->keyBy(fn ($rts) => $rts->date->format('Y-m-d'));
            foreach ($order->items as $item) {
                $date  = $item->checkin_date?->format('Y-m-d');
                $rts   = $date ? $dateRtsMap->get($date) : null;
                $price = (float) $item->price;
                $result = $calculator->calculateForDate($rts, $price, $date ?? '');
                $totalDiscount += (int) ($price - $result['final_price']);
            }
        } else {
            foreach ($order->items as $item) {
                $startTime = $item->checkin_date?->format('H:i:s');
                $rts       = $startTime ? $rtsMap->get($startTime) : null;
                $date      = $item->checkin_date?->format('Y-m-d');
                if (! $rts || ! $date || ! $rts->timeSlot) continue;
                $result = $calculator->calculate($rts, $date);
                $totalDiscount += $result['promo_discount'];
            }
        }

        return [(int) $totalDiscount, $applied];
    }

    private function recomputeCouponDiscountFromCodes(array $codes, float $baseAmount): int
    {
        if (empty($codes)) {
            return 0;
        }

        $coupons = Coupon::whereIn('code', $codes)->get()->keyBy('code');

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

    private function buildOrderResponse(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        $slots = $order->items->map(fn ($item) => [
            'date'          => $item->checkin_date?->format('Y-m-d'),
            'checkin_time'  => $item->checkin_date?->format('H:i'),
            'checkout_time' => $item->checkout_date?->format('H:i'),
            'price'         => (int) $item->price,
            'label'         => $item->name ? (explode(' - ', $item->name, 2)[1] ?? null) : null,
        ])->values()->toArray();

        $servicesResult = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => (int) $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => (int) $s->subtotal,
        ])->values()->toArray();

        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');

        $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
        if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
            $realFinalAmount = (int) round((int) $order->full_amount * 100 / $depositPct);
            $totalDiscount   = max(0, (int) $order->amount - $realFinalAmount);
        } else {
            $realFinalAmount = (int) $order->full_amount;
            $totalDiscount   = max(0, (int) $order->amount - (int) $order->full_amount);
        }

        return [
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
                'cccd_front'     => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'      => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'      => $order->cccd_data,
            ],
            'room' => [
                'id'   => $product?->id,
                'name' => $product?->name,
            ],
            'slots'    => $slots,
            'services' => $servicesResult,
            'deposit'  => $depositPct !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPct,
                'deposit_amount'   => (int) $order->full_amount,
                'remaining_amount' => max(0, $realFinalAmount - (int) $order->full_amount),
            ] : null,
            'summary' => [
                'slots_total'          => $slotsTotal,
                'discount_amount'      => $totalDiscount,
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinalAmount,
                'final_amount'         => (int) $order->full_amount,
            ],
        ];
    }

    private function buildListItem(Order $order): array
    {
        $firstItem = $order->items->first();
        $lastItem  = $order->items->last();

        $roomName = $firstItem?->product?->name
            ?? ($firstItem?->name ? explode(' - ', $firstItem->name, 2)[0] : null);

        return [
            'order_code'   => $order->order_code,
            'created_at'   => $order->created_at->format('Y-m-d H:i:s'),
            'status'       => $order->status,
            'room_name'    => $roomName,
            'checkin'      => $firstItem?->checkin_date?->format('Y-m-d H:i'),
            'checkout'     => $lastItem?->checkout_date?->format('Y-m-d H:i'),
            'final_amount' => (int) $order->full_amount,
        ];
    }

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
            Log::error('Guest PayOS link creation error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function rebuildPayOSLink(Order $order, string $itemName): void
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return;
            }

            $payOS = new PayOS($clientId, $apiKey, $checksumKey);

            $oldCode = $order->current_payos_code ?? (int) $order->order_code;
            try {
                $payOS->cancelPaymentLink((int) $oldCode);
            } catch (\Throwable) {
            }

            $newCode   = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
            $expiredAt = now()->addMinutes(15);

            $response = $payOS->createPaymentLink([
                'orderCode'   => $newCode,
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
                $order->update([
                    'checkout_url'       => $checkoutUrl,
                    'qr_code'            => $response['qrCode'] ?? null,
                    'expired_at'         => $expiredAt,
                    'current_payos_code' => $newCode,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Guest rebuildPayOSLink error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
