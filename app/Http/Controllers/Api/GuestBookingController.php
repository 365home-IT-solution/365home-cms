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
use Modules\Category\Entities\Category;
use Modules\Payment\App\Services\CccdScannerService;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use App\Services\CccdDeclarationService;
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
            'payment_method'          => 'sometimes|in:PayOS,cod',
            'payment_type'            => 'sometimes|in:full,deposit',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'cccd_front'              => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'device_token'            => 'sometimes|nullable|string|max:500',
            // CCCD người đi cùng (khung giờ qua đêm) — khách thứ 2 trở đi, key theo guest_index
            // (guests[2][front], guests[2][back], guests[3][front]...). Chỉ THỰC SỰ bắt buộc khi
            // có slot over_night, nhưng chưa biết được điều đó cho tới khi build xong
            // $rtsCollection ở dưới, nên ở đây chỉ validate ĐỊNH DẠNG nếu có gửi lên; check
            // "required đủ số khách" làm riêng ở bước 3.5.
            'guests'                  => 'sometimes|array',
            'guests.*.front'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'guests.*.back'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
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

        $this->guardExclusiveCoupons($couponCodes);

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

        if ($ageError = $this->validateCccdAge($cccdData)) {
            Storage::disk('public')->delete($cccdFront);
            Storage::disk('public')->delete($cccdBack);
            return $ageError;
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

        // ── 3.5 CCCD người đi cùng (bắt buộc khi có khung giờ qua đêm) ─────────
        // Luật Cư trú (hiệu lực 01/07/2026) yêu cầu khai báo lưu trú ĐỦ TỪNG NGƯỜI khi lưu trú
        // qua đêm — không chỉ người đặt phòng chính (cccd_front/cccd_back/cccd_data ở trên).
        // Không còn chặn cứng ở 2 khách nữa — khách 2..guest_count đều cần CCCD, gửi lên qua
        // guests[{index}][front]/guests[{index}][back] (index bắt đầu từ 2).
        $hasOvernight  = $rtsCollection->contains(fn ($rts) => (bool) $rts->over_night);
        $guestCccdRows = []; // [['guest_index' => 2, 'front' => path, 'back' => path, 'data' => [...]], ...]

        if ($hasOvernight) {
            $guestCount = (int) $request->input('guest_count');

            for ($guestIndex = 2; $guestIndex <= $guestCount; $guestIndex++) {
                $frontKey = "guests.{$guestIndex}.front";
                $backKey  = "guests.{$guestIndex}.back";

                if (! $request->hasFile($frontKey) || ! $request->hasFile($backKey)) {
                    $this->cleanupUploadedFiles($cccdFront, $cccdBack, $guestCccdRows);

                    return response()->json([
                        'message' => "Khung giờ qua đêm cần khai báo lưu trú cho khách thứ {$guestIndex} — vui lòng gửi kèm CCCD (mặt trước/sau) của khách này.",
                    ], 422);
                }

                $guestFront = $request->file($frontKey)->store('cccd', 'public');
                $guestBack  = $request->file($backKey)->store('cccd', 'public');

                $tempGuestOrder = new Order(['cccd_front' => $guestFront, 'cccd_back' => $guestBack]);
                $guestData      = app(CccdScannerService::class)->scanOrder($tempGuestOrder);

                if (! $guestData) {
                    Storage::disk('public')->delete($guestFront);
                    Storage::disk('public')->delete($guestBack);
                    $this->cleanupUploadedFiles($cccdFront, $cccdBack, $guestCccdRows);

                    return response()->json([
                        'message' => "Không đọc được QR trên ảnh CCCD của khách thứ {$guestIndex}. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.",
                    ], 422);
                }

                // Không kiểm tra tuổi người đi cùng (khác CCCD chính) — trẻ nhỏ đi cùng phụ huynh vẫn hợp lệ.
                $guestCccdRows[] = [
                    'guest_index' => $guestIndex,
                    'front'       => $guestFront,
                    'back'        => $guestBack,
                    'data'        => $guestData,
                ];
            }
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

        // Dữ liệu đối tác cũ (vd 365home) tổ chức category 2 CẤP: chi nhánh thật (parent_id NULL)
        // → danh mục phòng con CÙNG TÊN với phòng (parent_id = chi nhánh) — Product được gán
        // categorizable vào danh mục CON đó, không phải thẳng vào chi nhánh. Nếu dùng thẳng kết
        // quả categories()->first(), 'category_id' của đơn sẽ lưu NHẦM thành danh mục con (hiện
        // tên phòng thay vì tên chi nhánh khi admin mở sửa đơn) — leo lên tới đúng cấp chi nhánh
        // (parent_id NULL) trước khi lưu vào đơn.
        $category = $room->categories()->first();
        // Giới hạn tối đa 5 lần leo lên — đủ dư cho mọi cấu trúc thực tế (thường chỉ 1-2 cấp),
        // đồng thời chặn vòng lặp vô hạn nếu dữ liệu category bị lỗi (vd tự trỏ vòng lặp cha-con).
        for ($i = 0; $i < 5 && $category && $category->parent_id; $i++) {
            $parent = Category::find($category->parent_id);
            if (! $parent) {
                break;
            }
            $category = $parent;
        }
        $paymentMethod = $request->input('payment_method', 'PayOS');

        $depositPercentToSave = $depositInfo !== null ? (int) ($depositInfo['percentage']) : null;
        $appliedCouponCodes   = collect($appliedCoupons)->pluck('code')->values()->all();

        // ── 7. Tạo đơn trong transaction ─────────────────────────────────────
        $deviceToken = $request->input('device_token') ?: null;

        $order = DB::transaction(function () use (
            $room, $amountDue, $finalAmount, $subtotal, $buyerName, $buyerPhone,
            $cccdFront, $cccdBack, $cccdData, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave,
            $deviceToken, $guestCccdRows
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
                // Cả 'amount' và 'full_amount' lưu ĐÚNG TỔNG GIÁ thật của đơn (không phải số tiền
                // cọc cần trả ngay) — 'full_amount' CỐ ĐỊNH từ đây trở đi (không đổi dù sau này có
                // phát sinh phụ phí/điều chỉnh giá), 'amount' là nơi cập nhật khi giá thay đổi. Số
                // tiền THỰC TẾ cần thu qua PayOS (cọc hay đủ) được TÍNH LẠI riêng lúc tạo link
                // (xem createPayOSLink) từ full_amount * deposit_percent, không lưu trực tiếp vào
                // 2 cột này.
                'amount'          => $finalAmount,
                'full_amount'     => $finalAmount,
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
                // Đơn đặt qua API khách hàng KHÔNG đăng nhập bằng App\Models\User (mà là Customer/
                // khách vãng lai) nên BelongsToPartner::creating() không tự gán được partner_id
                // (chỉ tự gán khi người tạo là User — xem app/Models/Concerns/BelongsToPartner.php).
                // Gán thẳng theo đúng partner_id của CHÍNH phòng đang đặt — nếu không, đơn tạo ra
                // sẽ có partner_id = null, khiến admin mở sửa đơn bị "mất" thông tin đối tác/chi
                // nhánh (Select "Đối tác" không tự chọn được, "Chi nhánh" không hiện tên).
                'partner_id'      => $room->partner_id,
                'customer_id'     => null,
                'device_token'    => $deviceToken,
            ]);

            // CCCD khách thứ 2 trở đi (khung giờ qua đêm) — xem bước 3.5.
            foreach ($guestCccdRows as $guestRow) {
                $order->guestCccds()->create([
                    'guest_index' => $guestRow['guest_index'],
                    'cccd_front'  => $guestRow['front'],
                    'cccd_back'   => $guestRow['back'],
                    'cccd_data'   => $guestRow['data'],
                ]);
            }

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

        app(CccdDeclarationService::class)->upsertFromOrder($order->load('items'));

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

        // ── 10. Push notification + lưu DB cho guest ─────────────────────────

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
                'cccd_front'     => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'      => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'      => $order->cccd_data,
                'guests'         => $order->guestCccds->map(fn ($g) => [
                    'guest_index' => $g->guest_index,
                    'cccd_front'  => Storage::disk('public')->url($g->cccd_front),
                    'cccd_back'   => Storage::disk('public')->url($g->cccd_back),
                    'cccd_data'   => $g->cccd_data,
                ])->values(),
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
            // CCCD khách thứ 2 trở đi, gửi khi tăng guest_count cho đơn có khung giờ qua đêm —
            // cùng key guests[{index}][front/back] như lúc tạo đơn.
            'guests'                  => 'sometimes|array',
            'guests.*.front'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'guests.*.back'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'services'                => 'sometimes|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'device_token'            => 'sometimes|nullable|string|max:500',
        ]);

        $order = Order::with([
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
            'guestCccds',
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

        if ($request->has('device_token')) {
            $updates['device_token'] = $request->input('device_token') ?: null;
        }

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

            if ($ageError = $this->validateCccdAge($cccdData)) {
                if ($newFront) Storage::disk('public')->delete($newFront);
                if ($newBack)  Storage::disk('public')->delete($newBack);
                return $ageError;
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
        $priceInputsChanged = false;

        if ($request->has('guest_count')) {
            $newGuestCount = (int) $request->input('guest_count');
            $hasOvernight  = $order->items->contains('over_night', true);

            // Tăng số khách cho đơn qua đêm — khách mới (guest_index vượt số đã khai báo) phải
            // có CCCD kèm theo trong chính request này (guests[{index}][front/back]), nếu không
            // đã có sẵn từ trước (vd request update trước đó đã bổ sung rồi).
            if ($hasOvernight) {
                $declaredMax = max(1, (int) $order->guestCccds->max('guest_index'));
                $newGuestRows = [];

                for ($guestIndex = $declaredMax + 1; $guestIndex <= $newGuestCount; $guestIndex++) {
                    $frontKey = "guests.{$guestIndex}.front";
                    $backKey  = "guests.{$guestIndex}.back";

                    if (! $request->hasFile($frontKey) || ! $request->hasFile($backKey)) {
                        return response()->json([
                            'message' => "Tăng số khách cho đơn qua đêm cần khai báo lưu trú cho khách thứ {$guestIndex} — vui lòng gửi kèm CCCD (mặt trước/sau) của khách này.",
                        ], 422);
                    }

                    $guestFront = $request->file($frontKey)->store('cccd', 'public');
                    $guestBack  = $request->file($backKey)->store('cccd', 'public');

                    $tempGuestOrder = new Order(['cccd_front' => $guestFront, 'cccd_back' => $guestBack]);
                    $guestData      = app(CccdScannerService::class)->scanOrder($tempGuestOrder);

                    if (! $guestData) {
                        Storage::disk('public')->delete($guestFront);
                        Storage::disk('public')->delete($guestBack);
                        foreach ($newGuestRows as $row) {
                            Storage::disk('public')->delete($row['front']);
                            Storage::disk('public')->delete($row['back']);
                        }

                        return response()->json([
                            'message' => "Không đọc được QR trên ảnh CCCD của khách thứ {$guestIndex}. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.",
                        ], 422);
                    }

                    $newGuestRows[] = [
                        'guest_index' => $guestIndex,
                        'front'       => $guestFront,
                        'back'        => $guestBack,
                        'data'        => $guestData,
                    ];
                }

                foreach ($newGuestRows as $row) {
                    $order->guestCccds()->create([
                        'guest_index' => $row['guest_index'],
                        'cccd_front'  => $row['front'],
                        'cccd_back'   => $row['back'],
                        'cccd_data'   => $row['data'],
                    ]);
                }
            }

            $order->items()->update(['guest_count' => $newGuestCount]);
            $order->guest_count = $newGuestCount;
            $updates['guest_count'] = $newGuestCount;
            $priceInputsChanged = true;
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
                $servicesData[] = [
                    'service_id'   => $service->id,
                    'service_name' => $service->name,
                    'price'        => (int) $service->price,
                    'quantity'     => $quantity,
                    'subtotal'     => (int) $subtotal,
                ];
            }

            $order->services()->delete();
            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }
            $order->unsetRelation('services');
            $priceInputsChanged = true;
        }

        // Tính lại TOÀN BỘ tổng giá từ đầu (không dựa vào 'amount'/'full_amount' cũ để cộng trừ
        // dồn) mỗi khi số khách hoặc dịch vụ thay đổi — tránh sai số tích luỹ qua nhiều lần sửa.
        // Cả 'amount' và 'full_amount' đều lưu ĐÚNG TỔNG GIÁ MỚI (không phải tiền cọc); số tiền
        // cần thu ngay qua PayOS tính riêng qua depositDueAmount() lúc rebuild link bên dưới.
        if ($priceInputsChanged) {
            $newTotal = $this->recalculateOrderTotal($order);
            $updates['amount']      = $newTotal;
            $updates['full_amount'] = $newTotal;

            if ($newTotal !== $originalFullAmount && $order->checkout_url) {
                $updates['checkout_url'] = null;
                $updates['qr_code']      = null;
                $updates['expired_at']   = null;
            }
        }

        if (! empty($updates)) {
            $order->update($updates);
            $order->refresh();
        }

        if (isset($updates['cccd_data'])) {
            app(CccdDeclarationService::class)->upsertFromOrder($order->load('items'));
        }

        // Tạo lại link PayOS nếu giá thay đổi — số tiền thu qua PayOS luôn tính động (cọc hay đủ),
        // KHÔNG phải full_amount (nay là tổng giá cố định, không phải tiền cần thu ngay).
        $priceChanged = (int) $order->full_amount !== $originalFullAmount;
        $dueNow       = $order->depositDueAmount();
        if (
            $order->payment_method === 'PayOS' &&
            ($priceChanged || ! $order->checkout_url) &&
            $dueNow >= 2000
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
            'guestCccds',
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
            'items.product.roomType',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
            'accessCodes',
            'guestCccds',
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
    // GET /api/guest/orders/{order_code}/payment-status?phone={phone}
    // App polling để biết khi nào thanh toán xác nhận — xác thực qua buyer_phone
    // ══════════════════════════════════════════════════════════════════════

    public function paymentStatus(Request $request, string $orderCode): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', trim($request->input('phone')))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        if (in_array($order->status, ['paid', 'failed', 'cancelled', 'cancelled_payment'])) {
            return response()->json([
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
            ]);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json([
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
            ]);
        }

        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return response()->json([
                    'order_code'     => $order->order_code,
                    'status'         => $order->status,
                    'payment_status' => $order->payment_status,
                    'order_status'   => $order->order_status,
                ]);
            }

            $payOS = new PayOS($clientId, $apiKey, $checksumKey);

            // Xác định đang check payment nào: remaining hay cọc gốc
            $isRemaining = $order->status === 'deposit' && $order->remaining_payos_code;
            $payosCode   = $isRemaining
                ? (int) $order->remaining_payos_code
                : (int) $order->order_code;

            $response = $payOS->getPaymentLinkInformation($payosCode);
            $status   = $response['status'] ?? 'PENDING';

            if ($status === 'PAID') {
                if ($isRemaining) {
                    // Backup cho webhook: cập nhật nếu webhook chưa kịp chạy
                    if ($order->status === 'deposit') {
                        // full_amount CỐ ĐỊNH từ lúc tạo đơn (tổng giá thật) — không tính ngược từ
                        // tiền cọc nữa. Chỉ cập nhật 'amount' = tổng thực thu (gồm phụ phí nếu có).
                        $extraCharge = (int) ($order->extra_charge_amount ?? 0);
                        $totalPaid   = (int) $order->full_amount + $extraCharge;

                        $order->update([
                            'status'            => 'paid',
                            'amount'            => $totalPaid,
                            'remaining_paid_at' => now(),
                        ]);
                        $order->refresh();
                    }
                } elseif ($order->deposit_percent !== null && $order->status === 'pending') {
                    $order->update([
                        'status'          => 'deposit',
                        'checkout_url'    => null,
                        'deposit_paid_at' => now(),
                    ]);
                    $order->refresh();
                } elseif ($order->deposit_percent === null && $order->status === 'pending') {
                    $order->update(['status' => 'paid']);
                    $order->refresh();
                }
            }

            return response()->json([
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
            ]);

        } catch (\Throwable $e) {
            Log::error('Guest paymentStatus error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/guest/orders/{order_code}/remaining-payment
    // Thanh toán phần còn lại cho đơn đặt cọc — xác thực qua buyer_phone
    // ══════════════════════════════════════════════════════════════════════

    public function remainingPayment(Request $request, string $orderCode): JsonResponse
    {
        $request->validate([
            'buyer_phone' => 'required|string|max:20',
        ]);

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', trim($request->input('buyer_phone')))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        if ($order->status !== 'deposit') {
            return response()->json(['message' => 'Chỉ áp dụng cho đơn đang ở trạng thái đặt cọc.'], 422);
        }

        // full_amount CỐ ĐỊNH = tổng giá thật từ lúc tạo đơn — tiền cọc đã thu tính lại từ đó qua
        // depositDueAmount(), không tính ngược nữa.
        $depositPaid = $order->depositDueAmount();
        $extraCharge = (int) ($order->extra_charge_amount ?? 0);
        $remaining   = ((int) $order->full_amount - $depositPaid) + $extraCharge;

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
                'returnUrl'   => $request->input('return_url') ?? config('app.url') . '/payment/success?orderCode=' . $remainingCode,
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
                'amount'       => $remaining,
                'expired_at'   => $expiredAt->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Guest remainingPayment error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Lỗi khi tạo link thanh toán. Vui lòng thử lại.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/guest/orders/{order_code}/extra
    // Khách vãng lai tự đặt thêm dịch vụ/số khách trên đơn đã paid/deposit —
    // trả QR thanh toán khoản phát sinh ngay trong response.
    // ══════════════════════════════════════════════════════════════════════

    public function addExtra(Request $request, string $orderCode, \App\Services\OrderExtraBookingService $service): JsonResponse
    {
        $request->validate([
            'buyer_phone'                        => 'required|string|max:20',
            'services'                           => 'sometimes|array',
            'services.*.service_id'              => 'required_with:services|integer',
            'services.*.quantity'                => 'required_with:services|integer|min:1',
            // guest_count ở đây là SỐ KHÁCH THÊM (cộng dồn vào guest_count hiện có), không phải tổng mới.
            'guest_count'                        => 'sometimes|integer|min:1|max:50',
            'room_addition'                      => 'sometimes|array',
            'room_addition.type'                 => 'required_with:room_addition|in:slot,daily',
            'room_addition.product_id'           => 'sometimes|string',
            'room_addition.slots'                => 'required_if:room_addition.type,slot|array|min:1',
            'room_addition.slots.*.timeslot_id'  => 'required_with:room_addition.slots|integer',
            'room_addition.slots.*.date'         => 'required_with:room_addition.slots|date_format:d-m-Y|after_or_equal:today',
            'room_addition.checkin_date'         => 'required_if:room_addition.type,daily|date_format:d-m-Y|after_or_equal:today',
            'room_addition.checkout_date'        => 'required_if:room_addition.type,daily|date_format:d-m-Y|after:room_addition.checkin_date',
            // CCCD khách đi cùng — chỉ bắt buộc khi phần đặt thêm có khung giờ qua đêm (check ở Service).
            'guests'                              => 'sometimes|array',
            'guests.*.front'                      => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'guests.*.back'                       => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $order = Order::with(['items.product.additionalServices', 'services'])
            ->where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', trim($request->input('buyer_phone')))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        $result = $service->addExtra(
            $order,
            $request->input('services', []),
            $request->has('guest_count') ? (int) $request->input('guest_count') : null,
            $request->input('room_addition'),
            $this->extractGuestFiles($request),
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * Trích UploadedFile theo guest_index từ multipart 'guests[{n}][front/back]'.
     *
     * @return array<int, array{front?:\Illuminate\Http\UploadedFile, back?:\Illuminate\Http\UploadedFile}>
     */
    private function extractGuestFiles(Request $request): array
    {
        $guestFiles = [];

        foreach ((array) $request->file('guests', []) as $guestIndex => $files) {
            $guestFiles[(int) $guestIndex] = [
                'front' => $files['front'] ?? null,
                'back'  => $files['back'] ?? null,
            ];
        }

        return $guestFiles;
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/guest/orders/{order_code}/extra-charge-qr
    // Tạo lại QR PayOS cho khoản phát sinh (đặt thêm) đang chờ thanh toán — xác thực
    // qua buyer_phone, dùng khi khách bận không thanh toán kịp, QR cũ hết hạn.
    // ══════════════════════════════════════════════════════════════════════

    public function extraChargeQr(Request $request, string $orderCode, \App\Services\OrderExtraBookingService $service): JsonResponse
    {
        $request->validate(['buyer_phone' => 'required|string|max:20']);

        $order = Order::where('order_code', $orderCode)
            ->whereNull('customer_id')
            ->where('buyer_phone', trim($request->input('buyer_phone')))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại hoặc số điện thoại không khớp.'], 404);
        }

        $result = $service->regenerateExtraChargeQr($order);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result);
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
                // Đặt theo ngày (daily) luôn ở qua đêm — checkin ngày này, checkout ngày sau.
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

    private function parseDiscountRule(float $amount, string $rule): float
    {
        if (str_contains($rule, '%')) {
            $pct = (float) str_replace('%', '', $rule);
            return $amount * ($pct / 100);
        }

        return (float) str_replace(['.', ','], '', $rule);
    }

    /**
     * Cho phép áp nhiều mã/đơn, TRỪ mã is_exclusive=true — cùng quy tắc BookingController::guardExclusiveCoupons().
     */
    private function guardExclusiveCoupons(array $codes): void
    {
        if (count($codes) < 2) {
            return;
        }

        $exclusiveCodes = Coupon::whereIn('code', $codes)->where('is_exclusive', true)->pluck('code');

        if ($exclusiveCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'coupon_codes' => ['Mã "' . $exclusiveCodes->implode('", "') . '" không thể dùng chung với mã giảm giá khác.'],
            ]);
        }
    }

    /**
     * Áp dụng coupon cho guest: chấp nhận coupon active, công khai (không cá nhân).
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

            // Coupon cá nhân (customer_id trực tiếp hoặc gán qua coupon_customers pivot,
            // vd: coupon gắn theo hạng thành viên) không áp dụng được cho đơn khách vãng lai.
            if ($coupon->customer_id !== null || $coupon->customers()->exists()) {
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

    /**
     * Tính lại discount đúng cho slot orders, xử lý cả full_booking và non-full_booking.
     * Returns: [$promoDiscount, $promoApplied, $systemDiscount, $systemDiscountInfo, $couponDiscount, $couponBase]
     */
    // Tính lại TOÀN BỘ tổng giá của đơn từ đầu (items + dịch vụ + phụ thu khách - giảm giá) dựa
    // trên dữ liệu HIỆN TẠI đã lưu (không cộng/trừ dồn từ giá trị amount/full_amount cũ) — dùng khi
    // khách tự sửa số khách/dịch vụ lúc đơn còn pending, tránh sai số tích luỹ qua nhiều lần sửa.
    private function recalculateOrderTotal(Order $order): int
    {
        $order->loadMissing(['items.product', 'services']);

        $itemsSum      = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');

        [$promoDiscount, , $systemDiscount, , $couponDiscount] = $this->computeSlotDiscounts($order, $itemsSum);
        $totalDiscount  = $promoDiscount + $systemDiscount + $couponDiscount;
        $slotFinalPrice = max(0, $itemsSum - $totalDiscount);

        $room           = $order->items->first()?->product;
        $guestSurcharge = 0;

        if ($room) {
            $config    = $room->room_config ?? [];
            $fee       = (int) ($config['extra_guest_fee'] ?? 0);
            $threshold = (int) ($config['max_free_guests'] ?? 2);
            $guests    = (int) $order->guest_count;

            if ($fee > 0 && $guests > $threshold) {
                $isSlotType     = (int) $room->styles === 1;
                $nights         = $isSlotType ? $this->countNights($order->items) : max(1, $order->items->count());
                $guestSurcharge = ($guests - $threshold) * $fee * $nights;
            }
        }

        return $slotFinalPrice + $servicesTotal + $guestSurcharge;
    }

    private function computeSlotDiscounts(Order $order, int $itemsSum): array
    {
        $product     = $order->items->first()?->product;
        $couponCodes = is_array($order->coupon_codes) ? $order->coupon_codes
            : ($order->coupon_code ? [$order->coupon_code] : []);

        if (! $product) {
            $couponDiscount = $this->recomputeCouponDiscountFromCodes($couponCodes, $itemsSum);
            return [0, [], 0, null, $couponDiscount, $itemsSum];
        }

        // Reconstruct slot summary from order items to check full_booking
        $rtsMap = $product->roomTimeSlots
            ->whereNull('date')
            ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);

        $slotSummary = [];
        if ($rtsMap->isNotEmpty()) {
            foreach ($order->items as $item) {
                $startTime = $item->checkin_date?->format('H:i:s');
                $rts       = $startTime ? $rtsMap->get($startTime) : null;
                if ($rts) {
                    $slotSummary[] = [
                        'timeslot_id' => $rts->timeslot_id,
                        'date'        => $item->checkin_date->format('Y-m-d'),
                    ];
                }
            }
        }

        $promoDiscount      = 0;
        $promoApplied       = [];
        $systemDiscount     = 0;
        $systemDiscountInfo = null;

        if (! empty($slotSummary) && $this->checkFullDayBooking($slotSummary, $product)) {
            [$systemDiscount, $systemDiscountInfo] = $this->applyFullBookingDiscount((float) $itemsSum, $product);
        } else {
            [$promoDiscount] = $this->recomputePromotionDiscount($order);
            if (! empty($slotSummary)) {
                [$systemDiscount, $systemDiscountInfo] = $this->applyBulkDiscount(
                    count($slotSummary), $product, $itemsSum - $promoDiscount
                );
            }
        }

        $couponBase     = max(0, $itemsSum - $promoDiscount - $systemDiscount);
        $couponDiscount = $this->recomputeCouponDiscountFromCodes($couponCodes, $couponBase);

        return [$promoDiscount, $promoApplied, $systemDiscount, $systemDiscountInfo, $couponDiscount, $couponBase];
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

    private function buildCouponsInfo(array $codes, float $baseAmount): array
    {
        if (empty($codes)) {
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

        // ── Guest surcharge ───────────────────────────────────────────────────
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

        // ── Discounts (promotion / system / coupon) ───────────────────────────
        $couponCodes = is_array($order->coupon_codes) ? $order->coupon_codes : ($order->coupon_code ? [$order->coupon_code] : []);
        [$promoDiscount, $promoApplied, $systemDiscount, $systemDiscountInfo, $couponDiscount, $couponBase] = $this->computeSlotDiscounts($order, $slotsTotal);
        $couponsInfo = $this->buildCouponsInfo($couponCodes, $couponBase);

        // ── Deposit & totals ──────────────────────────────────────────────────
        // full_amount CỐ ĐỊNH = tổng giá thật của đơn (không phải tiền cọc) — không cần suy ngược
        // nữa. Tiền cọc cần thu tính riêng qua depositDueAmount().
        $totalSlotDiscount = $promoDiscount + $systemDiscount + $couponDiscount;
        $depositPct        = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
        $realFinalAmount   = (int) $order->full_amount;
        $totalDiscount     = $totalSlotDiscount;
        $depositDueNow     = $order->depositDueAmount();

        $slotsFinal = max(0, $slotsTotal - $promoDiscount - $systemDiscount - $couponDiscount);

        $result = [
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
                'note_for_admin' => $order->note_for_admin,
                'cccd_front'     => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'      => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'      => $order->cccd_data,
                'guests'         => $order->guestCccds->map(fn ($g) => [
                    'guest_index' => $g->guest_index,
                    'cccd_front'  => Storage::disk('public')->url($g->cccd_front),
                    'cccd_back'   => Storage::disk('public')->url($g->cccd_back),
                    'cccd_data'   => $g->cccd_data,
                ])->values(),
            ],
            'room' => [
                'id'        => $product?->id,
                'slug'      => $product?->slug,
                'name'      => $product?->name,
                'thumbnail' => $this->getRoomThumbnail($product),
            ],
            'slots'           => $slots,
            'services'        => $servicesResult,
            'guest_surcharge' => $guestSurchargeInfo,
            'promotions'      => $promoApplied,
            'system_discount' => $systemDiscountInfo,
            'coupons'         => $couponsInfo,
            'deposit'         => $depositPct !== null ? [
                'type'             => 'deposit',
                'percentage'       => $depositPct,
                'deposit_amount'   => $depositDueNow,
                'remaining_amount' => max(0, $realFinalAmount - $depositDueNow) + (int) ($order->extra_charge_amount ?? 0),
            ] : null,
            'summary' => [
                'slots_total'          => $slotsTotal,
                'promotion_discount'   => $promoDiscount,
                'system_discount'      => $systemDiscount,
                'coupon_discount'      => $couponDiscount,
                'discount_amount'      => $totalDiscount,
                'slots_final'          => $slotsFinal,
                'guest_surcharge'      => $guestSurchargeTotal,
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinalAmount,
                'final_amount'         => $realFinalAmount,
                'grand_total'          => $realFinalAmount + (int) ($order->extra_charge_amount ?? 0),
            ],
        ];

        $lockInfo = $this->buildLockInfo($order, $product);
        if ($lockInfo) {
            $result['lock_info'] = $lockInfo;
        }

        // Extra charge (phát sinh thêm sau khi đã thanh toán)
        if ($order->extra_charge_amount) {
            $isExpired = $order->extra_charge_expired_at && now()->gt($order->extra_charge_expired_at);
            $isPaid    = ! is_null($order->extra_charge_paid_at);
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

    private function buildLockInfo(Order $order, ?\Modules\Product\App\Models\Product $product): ?array
    {
        if (! $product || ! in_array($order->status, ['paid', 'deposit'])) {
            return null;
        }

        $pwdAnchorDate = $order->paid_at ?? $order->deposit_paid_at ?? $order->created_at;

        // Case 1: Mật khẩu thủ công (gate_password / room_password)
        $manualPwd = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($product, $pwdAnchorDate);
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

    private function buildListItem(Order $order): array
    {
        $firstItem = $order->items->first();
        $lastItem  = $order->items->last();
        $product   = $firstItem?->product;

        $roomName = $product?->name
            ?? ($firstItem?->name ? explode(' - ', $firstItem->name, 2)[0] : null);

        $hasPendingExtraCharge = $order->extra_charge_amount && is_null($order->extra_charge_paid_at);

        return [
            'order_code'                => $order->order_code,
            'created_at'                => $order->created_at->format('Y-m-d H:i:s'),
            'status'                    => $order->status,
            'payment_status'            => $order->payment_status,
            'order_status'              => $order->order_status,
            'room_id'                   => $product?->id,
            'room_slug'                 => $product?->slug,
            'room_name'                 => $roomName,
            'room_thumbnail'            => $this->getRoomThumbnail($product),
            'checkin'                   => $firstItem?->checkin_date?->format('Y-m-d H:i'),
            'checkout'                  => $lastItem?->checkout_date?->format('Y-m-d H:i'),
            'final_amount'              => (int) $order->full_amount,
            'has_pending_extra_charge' => $hasPendingExtraCharge,
            'extra_charge_amount'      => $hasPendingExtraCharge ? (int) $order->extra_charge_amount : null,
        ];
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
            $dueNow    = $order->depositDueAmount();

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $order->order_code,
                'amount'      => $dueNow,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
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
            $dueNow    = $order->depositDueAmount();

            $response = $payOS->createPaymentLink([
                'orderCode'   => $newCode,
                'amount'      => $dueNow,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => $dueNow]],
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

    /**
     * Xoá toàn bộ file CCCD đã upload (khách chính + các khách 2..N đã xử lý thành công cho tới
     * lúc gặp lỗi) khi phải huỷ tạo đơn giữa chừng — tránh rác file mồ côi trên storage.
     */
    private function cleanupUploadedFiles(string $mainFront, string $mainBack, array $guestCccdRows): void
    {
        Storage::disk('public')->delete($mainFront);
        Storage::disk('public')->delete($mainBack);

        foreach ($guestCccdRows as $row) {
            Storage::disk('public')->delete($row['front']);
            Storage::disk('public')->delete($row['back']);
        }
    }

    private function validateCccdAge(array $cccdData): ?JsonResponse
    {
        $dob = $cccdData['dob'] ?? null;
        if (empty($dob)) {
            return null;
        }

        try {
            $birthDate = Carbon::createFromFormat('d/m/Y', $dob)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if ($birthDate->age < 18) {
            return response()->json([
                'message' => 'Người đặt phòng phải đủ 18 tuổi trở lên.',
            ], 422);
        }

        return null;
    }
}
