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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use App\Exceptions\CccdIntakeException;
use App\Services\CccdDeclarationService;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use App\Services\PromotionCalculator;
use Modules\Promotion\App\Models\Coupon;
use App\Services\Payment\PayOsAccountResolver;
use PayOS\PayOS;

class BookingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // ── 1. Validate ──────────────────────────────────────────────────────
        $baseRules = [
            'type'                    => 'required|in:slot,monthly,daily',
            'room_id'                 => 'required|string',
            'guest_count'             => 'required|integer|min:1',
            'payment_method'          => 'sometimes|in:PayOS,cod',
            'payment_type'            => 'sometimes|in:full,deposit',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'return_url'              => 'sometimes|nullable|string|max:500',
            'cancel_url'              => 'sometimes|nullable|string|max:500',
            // CCCD người đặt: mặc định dùng hồ sơ; gửi cccd_qr_image để dùng CCCD khác cho đơn này
            // (hồ sơ trống/không hợp lệ thì bắt buộc). cccd_front/back chỉ cho app bản cũ.
            'cccd_qr_image'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_front'              => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'               => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            // Người đi cùng (qua đêm), key VỊ TRÍ 0-based: chọn người trong hồ sơ (companion_id)
            // HOẶC gửi ảnh mới (qr_image). App cũ: front/back, hoặc không gửi → tự lấy từ hồ sơ.
            'guests'                  => 'sometimes|array',
            'guests.*.companion_id'   => 'sometimes|nullable|integer',
            'guests.*.qr_image'       => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'guests.*.front'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'guests.*.back'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
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

        // Normalize: accept coupon_codes (preferred) hoặc coupon_code (array hoặc string)
        $couponInput = $request->input('coupon_codes');
        if (empty($couponInput)) {
            $raw = $request->input('coupon_code');
            if ($raw !== null) {
                $couponInput = is_array($raw) ? $raw : [$raw];
            }
        }
        $couponCodes = array_values(array_unique(array_map('strtoupper', array_filter((array) ($couponInput ?? [])))));

        $this->guardExclusiveCoupons($couponCodes);


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
        } elseif ($request->input('type') === 'daily') {
            [$basePrice, $summaryName, $itemsData, $rtsCollection, $slotSummary] = $this->buildDailyItems($request, $room);
        } else {
            [$basePrice, $summaryName, $itemsData] = $this->buildMonthlyItem($request, $room);
        }

        // ── 4.5 CCCD người đặt + người đi cùng ───────────────────────────────
        // Người đặt: ảnh gửi kèm (cccd_qr_image) hoặc cccd_data hợp lệ trong hồ sơ. Người đi cùng
        // (qua đêm — Luật Cư trú yêu cầu khai báo đủ từng người; daily LUÔN qua đêm): chọn
        // companion_id trong hồ sơ hoặc gửi ảnh mới, xem CccdIntakeService::readGuestsFromRequest.
        // Quét trên file tạm, kiểm tra cấu trúc + dưới 16 tuổi (tại ngày nhận phòng) + trùng
        // người; đạt hết mới lưu ảnh (bước 7). Lỗi → 422 {message, code, field}.
        $intake = app(CccdIntakeService::class);
        $actor  = 'customer:' . $customer->id;
        $booker = $intake->readFromRequest($request, 'cccd_qr_image', ['cccd_front', 'cccd_back'], 'cccd_qr_image', false, $actor);

        if ($booker) {
            $cccdData = $booker['data'];
        } else {
            $cccdData = is_array($customer->cccd_data) ? $customer->cccd_data : null;
            if (CccdIdentity::validate($cccdData, requireQr: false) !== null) {
                throw new CccdIntakeException(
                    'Bạn cần cập nhật CCCD vào tài khoản hoặc gửi kèm ảnh CCCD (mặt có mã QR) trước khi đặt phòng.',
                    CccdIntakeException::REQUIRED,
                    'cccd_qr_image',
                    422,
                    ['error' => 'cccd_required'], // app cũ đang đọc key này
                );
            }
        }

        $hasOvernight = $request->input('type') === 'daily'
            || $rtsCollection->contains(fn ($rts) => (bool) $rts->over_night);
        $guestRows = $hasOvernight
            ? $intake->readGuestsFromRequest($request, max(0, (int) $request->input('guest_count') - 1), 2, $customer, $actor)
            : [];

        $intake->assertPeople(array_merge(
            [['field' => 'cccd_qr_image', 'label' => 'Người đặt phòng', 'is_booker' => true, 'data' => $cccdData]],
            array_map(fn ($row) => $row + ['is_booker' => false, 'extra' => $row['companion'] ? ['companion_id' => $row['companion']->id] : []], $guestRows),
        ), CccdIntakeService::checkinDateFromItems($itemsData));

        // Ảnh cho đơn: ảnh vừa gửi → lưu mới; lấy từ hồ sơ → COPY (snapshot) để sau này khách sửa/
        // xoá hồ sơ thì đơn cũ vẫn còn ảnh. Tạo đơn lỗi thì xoá hết (xem try/catch ở bước 7).
        $orderImages = $booker
            ? ['cccd_qr_image' => $intake->storeQrImage($booker['file']), 'cccd_front' => null, 'cccd_back' => null]
            : $intake->snapshotImages([
                'cccd_qr_image' => $customer->cccd_qr_image,
                'cccd_front'    => $customer->cccd_front,
                'cccd_back'     => $customer->cccd_back,
            ]);
        foreach ($guestRows as &$guestRow) {
            $guestRow['images'] = $guestRow['file']
                ? ['cccd_qr_image' => $intake->storeQrImage($guestRow['file']), 'cccd_front' => null, 'cccd_back' => null]
                : $intake->snapshotImages([
                    'cccd_qr_image' => $guestRow['companion']->cccd_qr_image,
                    'cccd_front'    => $guestRow['companion']->cccd_front,
                    'cccd_back'     => $guestRow['companion']->cccd_back,
                ]);
        }
        unset($guestRow);
        $storedCccdPaths = array_merge(array_values($orderImages), ...array_map(fn ($r) => array_values($r['images']), $guestRows));

        // Ghi nhớ CCCD vừa gửi vào hồ sơ để lần sau chọn lại (chống trùng/xung đột, không ghi đè
        // dữ liệu hợp lệ sẵn có) — làm trước khi tạo đơn như trước đây, để đơn lỗi (trùng slot)
        // thử lại vẫn dùng được.
        $profileSync = [
            'booker'     => $booker ? $intake->rememberCustomerCccd($customer, $cccdData, $orderImages['cccd_qr_image']) : false,
            'companions' => [],
        ];
        foreach ($guestRows as $guestRow) {
            if ($guestRow['file']) {
                $profileSync['companions'][] = [
                    'guest_index' => $guestRow['guest_index'],
                    'status'      => $intake->rememberCompanion($customer, $guestRow['data'], $guestRow['images']['cccd_qr_image']),
                ];
            }
        }

        // ── 5. Dịch vụ bổ sung ───────────────────────────────────────────────
        [$servicesTotal, $servicesData] = $this->buildServices($request, $room);

        // ── 5.5 Phụ thu số lượng người ─────────────────────────────────────────
        [$guestSurcharge, $guestSurchargeInfo] = $this->buildGuestSurcharge($request, $room, $slotSummary);

        $subtotal = $basePrice + $servicesTotal + $guestSurcharge;

        // ── 6. Áp dụng discount theo thứ tự ưu tiên ─────────────────────────
        //
        //  Full booking (chọn hết slot trong ngày)
        //    → full_booking_discount + coupons, BỎ QUA promotion + bulk
        //
        //  Không full booking
        //    → promotion → bulk → coupons
        //
        //  Coupon stack: % trước, fixed sau; mỗi coupon áp trên số tiền còn lại.
        //
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
                [$couponDiscount, $appliedCoupons] = $this->applyMultipleCoupons(
                    $couponCodes,
                    $couponBase,
                    $room,
                    $rtsCollection,
                    $customer
                );
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
                [$couponDiscount, $appliedCoupons] = $this->applyMultipleCoupons(
                    $couponCodes,
                    $couponBase,
                    $room,
                    $rtsCollection,
                    $customer
                );
            }
        }

        $discountAmount = $promotionDiscount + $systemDiscount + $couponDiscount;
        $slotFinalPrice = max(0, $basePrice - $discountAmount);
        $finalAmount    = $slotFinalPrice + $servicesTotal + $guestSurcharge;

        // ── Deposit (chỉ daily) ───────────────────────────────────────────────
        $paymentMethod = $request->input('payment_method', 'PayOS');
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

        // Dữ liệu đối tác cũ (vd 365home) tổ chức category 2 CẤP: chi nhánh thật (parent_id NULL)
        // → danh mục phòng con CÙNG TÊN với phòng (parent_id = chi nhánh) — Product được gán
        // categorizable vào danh mục CON đó, không phải thẳng vào chi nhánh. Nếu dùng thẳng kết
        // quả categories()->first(), 'category_id' của đơn sẽ lưu NHẦM thành danh mục con (hiện
        // tên phòng thay vì tên chi nhánh khi admin mở sửa đơn) — leo lên tới đúng cấp chi nhánh
        // (parent_id NULL) trước khi lưu vào đơn.
        $category = $room->categories()->first();
        for ($i = 0; $i < 5 && $category && $category->parent_id; $i++) {
            $parent = Category::find($category->parent_id);
            if (! $parent) {
                break;
            }
            $category = $parent;
        }

        $depositPercentToSave = $depositInfo !== null ? (int) ($depositInfo['percentage']) : null;

        // Lấy danh sách code coupon đã áp dụng thành công
        $appliedCouponCodes = collect($appliedCoupons)->pluck('code')->values()->all();

        // ── 7. Tạo đơn + items + services trong transaction ──────────────────
        try {
            $order = DB::transaction(function () use (
                $room, $amountDue, $finalAmount, $subtotal, $buyerName, $buyerPhone,
                $customer, $category, $itemsData, $servicesData,
                $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave,
                $guestRows, $orderImages, $cccdData
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
                        ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit']))
                        ->exists();

                    if ($conflict) {
                        throw ValidationException::withMessages([
                            'slots' => ['Khung giờ vừa được người khác đặt. Vui lòng chọn khung giờ khác.'],
                        ]);
                    }
                }

                $firstCode = $appliedCouponCodes[0] ?? null;

                // Số tiền giảm của TỪNG mã — chỉ lưu tạm ở đây, KHÔNG trừ used_count ngay (xem
                // CouponUsageLedger::confirm(), gọi từ OrderObserver đúng lúc đơn thanh toán thành công).
                $couponDiscountAmounts = collect($appliedCoupons)
                    ->mapWithKeys(fn ($c) => [$c['code'] => $c['discount_amount'] ?? null])
                    ->all();

                $order = Order::create([
                    // Cả 'amount' và 'full_amount' lưu ĐÚNG TỔNG GIÁ thật của đơn (không phải số tiền
                    // cọc cần trả ngay) — 'full_amount' CỐ ĐỊNH từ đây trở đi, 'amount' là nơi cập nhật
                    // khi giá thay đổi sau này. Số tiền cần thu qua PayOS (cọc hay đủ) tính riêng qua
                    // Order::depositDueAmount(), không lưu trực tiếp vào 2 cột này.
                    'amount'          => $finalAmount,
                    'full_amount'     => $finalAmount,
                    'deposit_percent' => $depositPercentToSave,
                    'coupon_code'     => $firstCode,           // backward compat
                    'coupon_codes'    => $appliedCouponCodes ?: null,
                    'coupon_discount_amounts' => $couponDiscountAmounts ?: null,
                    'description'     => 'Đặt phòng - ' . $room->name,
                    'buyer_name'      => $buyerName,
                    'buyer_phone'     => $buyerPhone,
                    'payment_method'  => $paymentMethod,
                    'status'          => 'pending',
                    'guest_count'     => $request->guest_count,
                    'category_id'     => $category?->id,
                    // Đơn đặt qua API khách hàng đăng nhập (Customer, không phải App\Models\User) nên
                    // BelongsToPartner::creating() không tự gán được partner_id — gán thẳng theo đúng
                    // partner_id của phòng đang đặt (xem giải thích chi tiết ở GuestBookingController).
                    'partner_id'      => $room->partner_id,
                    'customer_id'     => $customer?->id,
                    'cccd_qr_image'   => $orderImages['cccd_qr_image'],
                    'cccd_front'      => $orderImages['cccd_front'],
                    'cccd_back'       => $orderImages['cccd_back'],
                    'cccd_data'       => $cccdData,
                ]);

                // CCCD khách thứ 2 trở đi (khung giờ qua đêm) — bản chụp tại thời điểm đặt (dữ liệu +
                // ảnh copy), giữ companion_id để truy vết người đi cùng trong hồ sơ, xem bước 4.5.
                foreach ($guestRows as $guestRow) {
                    $order->guestCccds()->create([
                        'guest_index'  => $guestRow['guest_index'],
                        'companion_id' => $guestRow['companion']?->id,
                        ...$guestRow['images'],
                        'cccd_data'    => $guestRow['data'],
                    ]);
                }

                foreach ($itemsData as $itemData) {
                    $order->items()->create($itemData);
                }

                foreach ($servicesData as $svc) {
                    $order->services()->create($svc);
                }

                // KHÔNG tăng used_count ở đây nữa — mã chỉ thực sự bị trừ lượt khi đơn thanh toán thành
                // công (xem CouponUsageLedger::confirm(), gọi từ OrderObserver).

                // Khai báo lưu trú (trước đây đơn đặt qua app có tài khoản KHÔNG tạo khai báo) — cùng
                // transaction để đơn và cccd_declarations luôn nhất quán.
                app(CccdDeclarationService::class)->upsertFromOrder($order->load(['items', 'guestCccds']));

                return $order;
            });
        } catch (\Throwable $e) {
            $intake->deleteImages($storedCccdPaths);
            throw $e;
        }

        // ── 8. Tạo link PayOS ────────────────────────────────────────────────
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

        // Loại bỏ _model khỏi response
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
                ...CccdIntakeService::imageUrls($order),
                'cccd_data'      => $order->cccd_data,
                'guests'         => $order->guestCccds->map(fn ($g) => [
                    'guest_index'  => $g->guest_index,
                    'companion_id' => $g->companion_id,
                    ...CccdIntakeService::imageUrls($g),
                    'cccd_data'    => $g->cccd_data,
                ])->values(),
            ],
            // Kết quả ghi nhớ CCCD vừa gửi vào hồ sơ: booker true/false; companions[].status =
            // created|updated|conflict (cùng số CCCD nhưng khác họ tên/ngày sinh → giữ dữ liệu cũ)|skipped.
            'cccd_profile_sync' => $profileSync,
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
            'deposit' => $depositInfo,
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

    // ── Slot (nhiều khung giờ) ────────────────────────────────────────────────

    private function buildSlotItems(Request $request, Product $room): array
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
                ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit']))
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

        // Gom lỗi của TẤT CẢ khung giờ bị trùng/không hợp lệ trong 1 lần request, thay vì chỉ báo
        // đúng cái đầu tiên gặp phải rồi bắt khách sửa-gửi lại nhiều lần mới biết hết — mỗi lỗi vẫn
        // gắn đúng "slots.{index}.field" để FE biết chính xác ô nào trong mảng slots[] đã gửi lên bị
        // lỗi gì, tự đối chiếu ngược lại request của chính mình để bỏ tick đúng ô.
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
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'confirmed']))
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
            $dateStr = $current->format('Y-m-d');
            $rts     = $slotsByDate->get($dateStr);

            $nightPrice      = $rts?->price !== null ? (float) $rts->price : $basePrice;
            $checkinTime     = $rts?->checkin  ?? $defCheckin;
            $checkoutTime    = $rts?->checkout ?? $defCheckout;
            $nextDate        = $current->copy()->addDay()->format('Y-m-d');
            $checkinDt       = Carbon::parse("{$dateStr} {$checkinTime}");
            $checkoutDt      = Carbon::parse("{$nextDate} {$checkoutTime}");

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

    private function buildMonthlyItem(Request $request, Product $room): array
    {
        $checkin  = Carbon::parse($request->checkin_date);
        $checkout = Carbon::parse($request->checkout_date);

        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit']))
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

    // ── Multiple coupons ──────────────────────────────────────────────────────

    /**
     * Áp dụng nhiều mã giảm giá theo thứ tự:
     *   1. Coupon % (áp trên số tiền lớn hơn → lợi hơn cho customer)
     *   2. Coupon fixed
     * Mỗi coupon áp trên số tiền còn lại sau coupon trước.
     *
     * Trả về [totalDiscount, appliedList]
     * appliedList: mỗi phần tử có 'code'/'discount_amount' để lưu vào coupon_discount_amounts của
     * đơn — used_count CHỈ tăng sau này lúc đơn thanh toán thành công (CouponUsageLedger::confirm()).
     */
    /**
     * Cho phép áp NHIỀU mã/đơn như bình thường, TRỪ mã nào được đánh dấu is_exclusive=true — mã đó
     * không được dùng chung với BẤT KỲ mã nào khác trong cùng 1 lần gửi (không phân biệt mã còn
     * lại có exclusive hay không). Dùng chung bởi BookingController/GuestBookingController/
     * OrderController — cùng 1 quy tắc cho cả tạo đơn mới lẫn sửa coupon của đơn đang pending.
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

    private function applyMultipleCoupons(
        array $codes,
        float $orderAmount,
        Product $room,
        Collection $rtsCollection,
        \App\Models\Customer $customer
    ): array {
        if (empty($codes)) {
            return [0, []];
        }

        // Loại trùng, uppercase
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));

        $coupons = [];
        foreach ($codes as $index => $code) {
            $coupon = $this->validateOneCoupon($code, $index, $room, $rtsCollection, $customer, $orderAmount);
            $coupons[] = $coupon;
        }

        // Sắp xếp: percentage trước, fixed sau
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
                '_model'          => $coupon, // chỉ dùng nội bộ, loại bỏ khỏi response (xem dưới)
            ];
        }

        return [(int) $totalDiscount, $applied];
    }

    /**
     * Validate một mã coupon: tồn tại, còn hạn, còn lượt, đúng phòng,
     * và thuộc về customer nếu là coupon cá nhân.
     */
    private function validateOneCoupon(
        string $code,
        int $index,
        Product $room,
        Collection $rtsCollection,
        \App\Models\Customer $customer,
        float $orderAmount = 0
    ): Coupon {
        try {
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

            // Kiểm tra coupon cá nhân: customer_id trực tiếp hoặc gán qua coupon_customers pivot.
            // Coupon được coi là "cá nhân" nếu có customer_id hoặc đã từng gán cho ai đó qua pivot.
            $isRestricted = $coupon->customer_id !== null || $coupon->customers()->exists();
            if ($isRestricted) {
                \Illuminate\Support\Facades\Log::info('Coupon ownership check', [
                    'coupon_code' => $code,
                    'coupon_customer_id' => $coupon->customer_id,
                    'auth_customer_id' => $customer->id,
                    'match' => $coupon->customer_id === $customer->id,
                ]);

                $owns = $coupon->customer_id === $customer->id
                    || $coupon->customers()->where('customer_id', $customer->id)->exists();

                if (! $owns) {
                    throw ValidationException::withMessages([
                        $field => ["Mã \"{$code}\" không thuộc về tài khoản của bạn."],
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('validateOneCoupon error', [
                'coupon_code' => $code,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }

        if ($coupon->hasReachedUsageLimit()) {
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
            'all_rooms', 'specific_room', 'specific_rooms' => $coupon->appliesToRoom($room->id),
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

    // ── PayOS ─────────────────────────────────────────────────────────────────

    private function createPayOSLink(Order $order, string $itemName, ?string $returnUrl = null, ?string $cancelUrl = null): void
    {
        try {
            $payOS = PayOsAccountResolver::forOrder($order);

            if (! $payOS) {
                return;
            }

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
