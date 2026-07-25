<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\BuildsRoomBooking;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

class BookingController extends Controller
{
    use BuildsRoomBooking;

    /**
     * POST /api/admin/orders
     * Admin/lễ tân tạo đơn đặt phòng hộ khách — dùng chung 1 API cho cả 2 trường hợp:
     *   - Khách vãng lai: gửi buyer_name + buyer_phone, không có customer_id/customer_phone.
     *   - Khách đã là thành viên: gửi customer_id (hoặc customer_phone để tự tra), đơn được
     *     gắn customer_id như đơn khách tự đặt qua app.
     *
     * Dùng lại NGUYÊN engine tính giá (slot/daily/monthly, khuyến mãi, giảm giá bulk/full-booking,
     * coupon, đặt cọc) của app khách hàng — xem BuildsRoomBooking — để giá tạo ra ở đây không lệch
     * với giá app hiển thị.
     *
     * KHÔNG bắt buộc CCCD ngay lúc tạo (khác app khách/guest) — lễ tân đã xác minh giấy tờ trực
     * tiếp tại quầy, không cần chụp ảnh ngay trong bước này.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        // ── 1. Validate ──────────────────────────────────────────────────────
        $baseRules = [
            'type'                    => 'required|in:slot,monthly,daily',
            'room_id'                 => 'required|string',
            'guest_count'             => 'required|integer|min:1',
            'customer_id'             => 'sometimes|nullable|integer|exists:customers,id',
            'customer_phone'          => 'sometimes|nullable|string|max:20',
            'buyer_name'              => 'sometimes|nullable|string|max:100',
            'buyer_phone'             => 'sometimes|nullable|string|max:20',
            'payment_method'          => 'sometimes|in:PayOS,cod',
            'payment_type'            => 'sometimes|in:full,deposit',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'note_for_admin'          => 'sometimes|nullable|string|max:500',
            'return_url'              => 'sometimes|nullable|string|max:500',
            'cancel_url'              => 'sometimes|nullable|string|max:500',
        ];

        if ($request->input('type') === 'slot') {
            $baseRules['date']                = 'sometimes|date_format:Y-m-d|after_or_equal:today';
            $baseRules['slots']               = 'required|array|min:1';
            $baseRules['slots.*.timeslot_id'] = 'required|integer';
            $baseRules['slots.*.date']        = 'sometimes|date_format:Y-m-d|after_or_equal:today';
        } else {
            $baseRules['checkin_date']  = 'required|date|after_or_equal:today';
            $baseRules['checkout_date'] = 'required|date|after:checkin_date';
        }

        if ($request->input('type') === 'daily') {
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

        // ── 2. Xác định khách: thành viên có sẵn hay khách vãng lai ────────────
        $customer = null;
        if ($request->filled('customer_id')) {
            $customer = Customer::find($request->integer('customer_id'));
        } elseif ($request->filled('customer_phone')) {
            $customer = Customer::where('phone', trim($request->input('customer_phone')))->first();
        }

        if ($customer) {
            $buyerName  = $request->filled('buyer_name')  ? trim($request->input('buyer_name'))  : $customer->fullname;
            $buyerPhone = $request->filled('buyer_phone') ? trim($request->input('buyer_phone')) : $customer->phone;
        } else {
            if (! $request->filled('buyer_name') || ! $request->filled('buyer_phone')) {
                throw ValidationException::withMessages([
                    'buyer_name' => ['Khách vãng lai cần buyer_name + buyer_phone. Nếu là khách đã đăng ký thành viên, gửi customer_id hoặc customer_phone.'],
                ]);
            }
            $buyerName  = trim($request->input('buyer_name'));
            $buyerPhone = trim($request->input('buyer_phone'));
        }

        // ── 3. Load phòng + kiểm tra quyền theo đối tác ────────────────────────
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

        // Nhân viên đối tác nền tảng (vd 365home) được đặt hộ cho MỌI đối tác — xem
        // User::belongsToPlatformPartner(). Nhân viên đối tác thường chỉ được đặt phòng
        // thuộc chính đối tác mình.
        if (! $admin->isSuperAdmin() && ! $admin->belongsToPlatformPartner() && $room->partner_id !== $admin->partner_id) {
            return response()->json(['message' => 'Bạn không có quyền đặt phòng của đối tác khác.'], 403);
        }

        // ── 4. Xây dựng items đặt phòng ──────────────────────────────────────
        $rtsCollection = collect();
        $slotSummary   = [];

        if ($request->input('type') === 'slot') {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildSlotItems($request, $room);
        } elseif ($request->input('type') === 'daily') {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildDailyItems($request, $room);
        } else {
            [$basePrice, $summaryName, $itemsData] = $this->buildMonthlyItem($request, $room);
        }

        // ── 5. Dịch vụ bổ sung ───────────────────────────────────────────────
        [$servicesTotal, $servicesData] = $this->buildServices($request, $room);

        // ── 5.5 Phụ thu số lượng người ─────────────────────────────────────────
        [$guestSurcharge, $guestSurchargeInfo] = $this->buildGuestSurcharge($request, $room, $slotSummary);

        // ── 6. Áp dụng discount theo thứ tự ưu tiên (giống app khách) ─────────
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
                [$couponDiscount, $appliedCoupons] = $this->applyBookingCoupons($couponCodes, $couponBase, $room, $rtsCollection, $customer);
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
                [$couponDiscount, $appliedCoupons] = $this->applyBookingCoupons($couponCodes, $couponBase, $room, $rtsCollection, $customer);
            }
        }

        $discountAmount = $promotionDiscount + $systemDiscount + $couponDiscount;
        $slotFinalPrice = max(0, $basePrice - $discountAmount);
        $finalAmount    = $slotFinalPrice + $servicesTotal + $guestSurcharge;

        // ── Deposit (chỉ daily) ───────────────────────────────────────────────
        $paymentMethod = $request->input('payment_method', 'cod');
        $amountDue     = $finalAmount;
        $depositInfo   = null;

        if ($request->input('type') === 'daily') {
            $depositMin  = (int) ($room->deposit_min_nights  ?? 0);
            $depositPct  = (int) ($room->deposit_multi_night ?? 50);
            $paymentType = $request->input('payment_type', 'full');

            if ($paymentType === 'deposit') {
                if ($paymentMethod === 'cod') {
                    throw ValidationException::withMessages([
                        'payment_type' => ['Đặt cọc không áp dụng cho phương thức thanh toán tiền mặt.'],
                    ]);
                }

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

        // Leo lên đúng cấp chi nhánh thật (parent_id NULL) — xem giải thích chi tiết ở
        // BookingController/GuestBookingController::store().
        $category = $room->categories()->first();
        for ($i = 0; $i < 5 && $category && $category->parent_id; $i++) {
            $parent = Category::find($category->parent_id);
            if (! $parent) {
                break;
            }
            $category = $parent;
        }

        $depositPercentToSave = $depositInfo !== null ? (int) ($depositInfo['percentage']) : null;
        $appliedCouponCodes   = collect($appliedCoupons)->pluck('code')->values()->all();

        // ── 7. Tạo đơn + items + services trong transaction ──────────────────
        $order = DB::transaction(function () use (
            $room, $finalAmount, $buyerName, $buyerPhone,
            $customer, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave,
            $admin
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
                        'slots' => ['Khung giờ vừa được đặt (hoặc do người khác). Vui lòng chọn khung giờ khác.'],
                    ]);
                }
            }

            $firstCode = $appliedCouponCodes[0] ?? null;

            $order = Order::create([
                'amount'          => $finalAmount,
                'full_amount'     => $finalAmount,
                'deposit_percent' => $depositPercentToSave,
                'coupon_code'     => $firstCode,
                'coupon_codes'    => $appliedCouponCodes ?: null,
                'description'     => 'Đặt phòng (admin) - ' . $room->name,
                'buyer_name'      => $buyerName,
                'buyer_phone'     => $buyerPhone,
                'payment_method'  => $paymentMethod,
                'status'          => 'pending',
                'guest_count'     => $request->guest_count,
                'note_for_admin'  => $request->input('note_for_admin'),
                'category_id'     => $category?->id,
                // Đơn gán theo đúng partner_id của PHÒNG đang đặt (không phải của admin đang tạo) —
                // nhân viên đối tác nền tảng có thể đặt hộ cho đối tác khác (đã check quyền ở bước 3),
                // nên partner_id phải phản ánh đúng chủ sở hữu phòng.
                'partner_id'      => $room->partner_id,
                'customer_id'     => $customer?->id,
                // Ghi nhận admin/lễ tân đã tạo đơn này — dùng để tra lại đơn đặt hộ (xem Order::creator()).
                'created_by'      => $admin->id,
                'cccd_front'      => $customer?->cccd_front,
                'cccd_back'       => $customer?->cccd_back,
                'cccd_data'       => $customer?->cccd_data,
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

        // ── 8. Tạo link PayOS (nếu chọn chuyển khoản thay vì tiền mặt) ────────
        if ($paymentMethod === 'PayOS' && $amountDue >= 2000) {
            $this->createPayOSLink($order, $summaryName, $request->input('return_url'), $request->input('cancel_url'));
        }

        // ── 9. Realtime: cập nhật trạng thái slot ────────────────────────────
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
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
                'payment_method' => $order->payment_method,
                'qr_code'        => $order->qr_code,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
                'customer_id'    => $order->customer_id,
                'created_by'     => $admin->fullname,
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
}
