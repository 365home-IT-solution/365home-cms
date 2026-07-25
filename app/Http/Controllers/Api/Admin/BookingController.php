<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\BuildsRoomBooking;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\CccdDeclarationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\App\Services\CccdScannerService;
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
     * CCCD (mặt trước/sau) là TÙY CHỌN (khác app khách/guest luôn bắt buộc) — lễ tân đã xác minh
     * giấy tờ trực tiếp tại quầy nên không bắt phải chụp ảnh ngay bước này; nếu có gửi kèm thì hệ
     * thống vẫn tự quét QR và lưu vào "Khai báo lưu trú" (Luật Cư trú) giống hệt luồng CMS admin —
     * xem CccdDeclarationService. Khung giờ qua đêm (over_night) với guest_count > 1 thì cho gửi
     * thêm CCCD của khách đi cùng qua guests[{guest_index}][front]/[back] (guest_index từ 2), cũng
     * không bắt buộc — chỉ những khách nào có gửi ảnh mới được quét/lưu.
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
            'status'                  => 'sometimes|in:pending,paid,deposit,failed,cancelled_payment,refunded',
            'coupon_codes'            => 'sometimes|nullable|array',
            'coupon_codes.*'          => 'string',
            'services'                => 'sometimes|nullable|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
            'note_for_admin'          => 'sometimes|nullable|string|max:500',
            'return_url'              => 'sometimes|nullable|string|max:500',
            'cancel_url'              => 'sometimes|nullable|string|max:500',
            // CCCD khách chính — không bắt buộc (xem docblock). Chỉ validate ĐỊNH DẠNG nếu có gửi.
            'cccd_front'              => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_back'               => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            // CCCD khách đi cùng (khung giờ qua đêm) — guests[{guest_index}][front|back], guest_index
            // từ 2. Cũng không bắt buộc, chỉ validate định dạng nếu có gửi.
            'guests'                  => 'sometimes|array',
            'guests.*.front'          => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guests.*.back'           => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
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

        // ── 4.5 CCCD (tùy chọn) — khách chính + khách đi cùng nếu có khung giờ qua đêm ─────────
        // type != 'slot' (đặt theo ngày) LUÔN là qua đêm — xem over_night => true hardcode trong
        // buildDailyItems(). $rtsCollection ở đó chỉ chứa RoomTimeSlot có type 'date' nên có thể
        // rỗng dù đơn thực chất qua đêm — phải check type riêng (giống BookingController khách hàng).
        $hasOvernight = $request->input('type') === 'daily'
            || $rtsCollection->contains(fn ($rts) => (bool) $rts->over_night);

        $cccdFront = null;
        $cccdBack  = null;
        $cccdData  = null;

        if ($request->hasFile('cccd_front') && $request->hasFile('cccd_back')) {
            $cccdFront = $request->file('cccd_front')->store('cccd', 'public');
            $cccdBack  = $request->file('cccd_back')->store('cccd', 'public');

            try {
                $tempOrder = new Order(['cccd_front' => $cccdFront, 'cccd_back' => $cccdBack]);
                $cccdData  = app(CccdScannerService::class)->scanOrder($tempOrder);
            } catch (\Throwable $e) {
                $cccdData = null;
                Log::warning('Admin API: quét CCCD khách chính thất bại', ['error' => $e->getMessage()]);
            }
            // KHÔNG bắt buộc đọc được QR — khác app khách/guest (BookingController/GuestBookingController)
            // luôn chặn đơn nếu quét lỗi. Lễ tân đã xác minh giấy tờ trực tiếp, ảnh chỉ để lưu hồ sơ/
            // khai báo lưu trú — quét lỗi thì vẫn tạo đơn bình thường, cccd_data để trống, sửa tay sau.
        }

        // guest_index bắt đầu từ 2 (1 = khách chính) — mỗi phần tử LUÔN có mặt trong mảng trả về dù
        // chưa gửi ảnh (front/back/data = null), để FE biết cần hiển thị đủ (guest_count - 1) ô nhập.
        $guestCccdRows = [];

        if ($hasOvernight) {
            $companionCount = max(0, (int) $request->input('guest_count') - 1);

            for ($i = 0; $i < $companionCount; $i++) {
                $guestIndex = $i + 2;
                $frontKey   = "guests.{$guestIndex}.front";
                $backKey    = "guests.{$guestIndex}.back";

                $row = ['guest_index' => $guestIndex, 'front' => null, 'back' => null, 'data' => null];

                if ($request->hasFile($frontKey) && $request->hasFile($backKey)) {
                    $row['front'] = $request->file($frontKey)->store('cccd', 'public');
                    $row['back']  = $request->file($backKey)->store('cccd', 'public');

                    try {
                        $row['data'] = app(CccdScannerService::class)->scanPaths($row['front'], $row['back']);
                    } catch (\Throwable $e) {
                        $row['data'] = null;
                        Log::warning('Admin API: quét CCCD khách đi cùng thất bại', [
                            'guest_index' => $guestIndex,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }

                $guestCccdRows[] = $row;
            }
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

        // Đơn PayOS LUÔN bắt đầu ở "pending" (đang xử lý) — trạng thái thật chỉ được cập nhật qua
        // webhook PayOS khi khách thanh toán (xem OrderController::paymentStatus/webhook), nên bỏ
        // qua status admin gửi lên nếu có. Đơn tiền mặt (cod) không qua cổng thanh toán nên admin có
        // thể chỉnh trạng thái ngay lúc tạo (vd đã thu tiền mặt tại quầy → 'paid' luôn).
        $initialStatus = $paymentMethod === 'PayOS' ? 'pending' : $request->input('status', 'pending');

        // ── 7. Tạo đơn + items + services trong transaction ──────────────────
        $order = DB::transaction(function () use (
            $room, $finalAmount, $buyerName, $buyerPhone,
            $customer, $category, $itemsData, $servicesData,
            $paymentMethod, $request, $appliedCoupons, $appliedCouponCodes, $depositPercentToSave,
            $admin, $initialStatus, $cccdFront, $cccdBack, $cccdData, $guestCccdRows
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
                'status'          => $initialStatus,
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
                // Ưu tiên ảnh CCCD admin vừa upload trong request này (tùy chọn); nếu không gửi thì
                // dùng lại CCCD đã lưu sẵn trong hồ sơ khách thành viên (hành vi cũ, không đổi).
                'cccd_front'      => $cccdFront ?? $customer?->cccd_front,
                'cccd_back'       => $cccdBack  ?? $customer?->cccd_back,
                'cccd_data'       => $cccdData  ?? $customer?->cccd_data,
            ]);

            // CCCD khách đi cùng (khung giờ qua đêm) — chỉ lưu những khách ĐÃ gửi ảnh, các ô còn
            // trống (chưa upload) không tạo bản ghi rỗng.
            foreach ($guestCccdRows as $guestRow) {
                if (! $guestRow['front'] || ! $guestRow['back']) {
                    continue;
                }

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

        // ── 8. Tạo link PayOS (nếu chọn chuyển khoản thay vì tiền mặt) ────────
        if ($paymentMethod === 'PayOS' && $amountDue >= 2000) {
            $this->createPayOSLink($order, $summaryName, $request->input('return_url'), $request->input('cancel_url'));
        }

        // Đơn tạo qua API admin (lễ tân lên đơn hộ) cũng có nghĩa vụ "Khai báo lưu trú" y hệt đơn
        // khách tự đặt — gọi lại đúng service dùng chung với CMS/app khách (xem CccdDeclarationService)
        // để không bỏ sót, dù CCCD lúc tạo có thể còn thiếu (guest_index=1 vẫn được khai báo với dữ
        // liệu rỗng, nhân viên bổ sung tay sau ở trang "Khai báo lưu trú").
        app(CccdDeclarationService::class)->upsertFromOrder($order->load('items'));

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
                'cccd_front'     => $order->cccd_front ? Storage::disk('public')->url($order->cccd_front) : null,
                'cccd_back'      => $order->cccd_back  ? Storage::disk('public')->url($order->cccd_back)  : null,
                'cccd_data'      => $order->cccd_data,
            ],
            'room' => [
                'id'   => $room->id,
                'name' => $room->name,
            ],
            // Qua đêm (over_night) + guest_count > 1 → luôn có đúng (guest_count - 1) phần tử, kể cả
            // khách chưa gửi ảnh (cccd_front/back/data = null) — FE dựa vào đây để hiển thị đủ số ô
            // nhập CCCD cần khai báo theo Luật Cư trú, không bắt buộc điền ngay lúc tạo đơn.
            'overnight'        => $hasOvernight,
            'guests'           => collect($guestCccdRows)->map(fn ($row) => [
                'guest_index' => $row['guest_index'],
                'cccd_front'  => $row['front'] ? Storage::disk('public')->url($row['front']) : null,
                'cccd_back'   => $row['back']  ? Storage::disk('public')->url($row['back'])  : null,
                'cccd_data'   => $row['data'],
            ])->values(),
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
