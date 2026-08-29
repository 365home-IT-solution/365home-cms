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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use App\Models\CustomerCompanion;
use App\Services\PromotionCalculator;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Promotion\App\Models\Coupon;
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

        if (empty($customer->cccd_front) || empty($customer->cccd_back) || empty($customer->cccd_data)) {
            return response()->json([
                'message' => 'Bạn cần cập nhật CCCD/CMND vào tài khoản trước khi đặt phòng.',
                'error'   => 'cccd_required',
            ], 422);
        }

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

        // ── 4.5 CCCD người đi cùng (bắt buộc khi có khung giờ qua đêm) ─────────
        // Luật Cư trú (hiệu lực 01/07/2026) yêu cầu khai báo lưu trú ĐỦ TỪNG NGƯỜI khi lưu trú
        // qua đêm — không chỉ người đặt phòng chính (CCCD lấy từ hồ sơ customer ở trên).
        //
        // Ưu tiên tái sử dụng CCCD người đi cùng đã lưu sẵn trong hồ sơ (customer_companions,
        // theo thứ tự id). Nếu hồ sơ chưa đủ số người cần thiết, cho phép gửi kèm CCCD (mặt
        // trước/sau) trực tiếp trong request tạo đơn này — giống luồng guest — qua key
        // guests[{i}][front]/guests[{i}][back], {i} là VỊ TRÍ 0-based trong danh sách người đi
        // cùng (đã đối chiếu log thực tế — FE luôn đánh số từ 0, KHÔNG theo guest_index 2,3,4...
        // dùng nội bộ/DB, xem GuestBookingController::store() — cùng bug, đã vá song song).
        // Sau khi quét QR hợp lệ, lưu luôn vào customer_companions để các lần đặt phòng sau
        // không cần upload lại.
        //
        // type != 'slot' (đặt theo ngày) LUÔN là qua đêm — xem over_night => true hardcode trong
        // buildDailyItems(). $rtsCollection ở đó chỉ chứa RoomTimeSlot có type 'date' (giá đặc
        // biệt theo ngày cụ thể) nên có thể rỗng dù đơn thực chất qua đêm — phải check type riêng.
        $hasOvernight  = $request->input('type') === 'daily'
            || $rtsCollection->contains(fn ($rts) => (bool) $rts->over_night);
        $guestCccdRows      = [];
        $newCompanionUploads = [];

        if ($hasOvernight) {
            $guestCount     = (int) $request->input('guest_count');
            $companionCount = $guestCount - 1;

            if ($companionCount > 0) {
                $existingCompanions = $customer->companions()->orderBy('id')->get();

                for ($i = 0; $i < $companionCount; $i++) {
                    $guestIndex = $i + 2;

                    if ($existingCompanions->has($i)) {
                        $companion = $existingCompanions[$i];
                        $guestCccdRows[] = [
                            'guest_index' => $guestIndex,
                            'front'       => $companion->cccd_front,
                            'back'        => $companion->cccd_back,
                            'data'        => $companion->cccd_data,
                        ];
                        continue;
                    }

                    // Chưa có sẵn trong hồ sơ — chấp nhận upload trực tiếp trong request này, key
                    // theo vị trí 0-based $i (KHÔNG phải $guestIndex — xem comment ở trên).
                    $frontKey = "guests.{$i}.front";
                    $backKey  = "guests.{$i}.back";

                    if (! $request->hasFile($frontKey) || ! $request->hasFile($backKey)) {
                        Log::warning('Booking: thiếu CCCD người đi cùng — đối chiếu key thực nhận', [
                            'expected_front_key'   => $frontKey,
                            'expected_back_key'    => $backKey,
                            'guest_count'          => $guestCount,
                            'existing_companions'  => $existingCompanions->count(),
                            'position_index_i'     => $i,
                            'content_type'         => $request->header('Content-Type'),
                            'file_field_paths'     => $this->flattenFileFieldPaths($request->allFiles()),
                            'non_file_input_keys'  => array_keys($request->except(array_keys($request->allFiles()))),
                            'guests_raw_input'     => $request->input('guests'),
                        ]);

                        $this->cleanupNewCompanionUploads($newCompanionUploads);

                        return response()->json([
                            'message' => "Khung giờ qua đêm cần khai báo lưu trú cho khách thứ {$guestIndex} — vui lòng gửi kèm CCCD (mặt trước/sau) của khách này hoặc thêm vào hồ sơ trước.",
                            'error'   => 'companion_cccd_required',
                        ], 422);
                    }

                    $guestFront = $request->file($frontKey)->store('cccd', 'public');
                    $guestBack  = $request->file($backKey)->store('cccd', 'public');

                    $tempGuestOrder = new Order(['cccd_front' => $guestFront, 'cccd_back' => $guestBack]);
                    $guestData      = app(CccdScannerService::class)->scanOrder($tempGuestOrder);

                    if (! $guestData) {
                        Storage::disk('public')->delete($guestFront);
                        Storage::disk('public')->delete($guestBack);
                        $this->cleanupNewCompanionUploads($newCompanionUploads);

                        return response()->json([
                            'message' => "Không đọc được mã QR trên CCCD khách thứ {$guestIndex}. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình.",
                        ], 422);
                    }

                    $guestCccdRows[] = [
                        'guest_index' => $guestIndex,
                        'front'       => $guestFront,
                        'back'        => $guestBack,
                        'data'        => $guestData,
                    ];

                    $newCompanionUploads[] = [
                        'cccd_front' => $guestFront,
                        'cccd_back'  => $guestBack,
                        'cccd_data'  => $guestData,
                    ];
                }
            }
        }

        // Lưu ngay các CCCD người đi cùng vừa upload vào hồ sơ (customer_companions) — độc lập
        // với việc đơn có tạo thành công hay không, để lần đặt sau tái sử dụng được luôn, không
        // bắt khách quét QR lại nếu đơn này bị conflict slot phải thử lại phòng khác.
        foreach ($newCompanionUploads as $row) {
            CustomerCompanion::create([
                'customer_id' => $customer->id,
                'full_name'   => $row['cccd_data']['full_name'] ?? null,
                'cccd_front'  => $row['cccd_front'],
                'cccd_back'   => $row['cccd_back'],
                'cccd_data'   => $row['cccd_data'],
            ]);
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
        $order = DB::transaction(function () use (
            $room, $amountDue, $finalAmount, $subtotal, $buyerName, $buyerPhone,
            $customer, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave,
            $guestCccdRows
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
                'cccd_front'      => $customer?->cccd_front,
                'cccd_back'       => $customer?->cccd_back,
                'cccd_data'       => $customer?->cccd_data,
            ]);

            // CCCD khách thứ 2 trở đi (khung giờ qua đêm) — snapshot từ customer_companions tại
            // thời điểm đặt, xem bước 4.5.
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

            // Tăng lượt dùng cho tất cả coupon đã áp dụng
            foreach ($appliedCoupons as $couponInfo) {
                if (isset($couponInfo['_model'])) {
                    $couponInfo['_model']->incrementUsage((string) $order->id, $customer?->id, $order->category_id, $couponInfo['discount_amount'] ?? null);
                }
            }

            return $order;
        });

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

    /**
     * Xoá các file CCCD người đi cùng vừa upload trong request hiện tại (chưa kịp lưu vào
     * customer_companions) khi phải huỷ ngang do lỗi ở người tiếp theo trong vòng lặp.
     */
    // Liệt kê dạng "dot path" (vd guests.2.front) của MỌI field file thực sự có trong request —
    // dùng để log đối chiếu key FE thực tế gửi lên với key server đang đợi, không phụ thuộc FE
    // đặt tên/đánh số key thế nào (kể cả sai quy ước guests[{index}][front]).
    private function flattenFileFieldPaths(array $files, string $prefix = ''): array
    {
        $paths = [];

        foreach ($files as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $paths = array_merge($paths, $this->flattenFileFieldPaths($value, $path));
            } elseif ($value instanceof \Illuminate\Http\UploadedFile) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function cleanupNewCompanionUploads(array $uploads): void
    {
        foreach ($uploads as $row) {
            Storage::disk('public')->delete($row['cccd_front']);
            Storage::disk('public')->delete($row['cccd_back']);
        }
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
     * appliedList: mỗi phần tử có _model để gọi incrementUsage() trong transaction.
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
                '_model'          => $coupon, // chỉ dùng nội bộ để incrementUsage
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
