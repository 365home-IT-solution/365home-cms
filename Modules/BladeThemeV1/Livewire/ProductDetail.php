<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Livewire\WithFileUploads;


use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;
use Modules\BladeThemeV1\Traits\ValidationRulesTrait;
use Modules\BladeThemeV1\Traits\PropertiesProductDetail;
use Modules\BladeThemeV1\Traits\HasTimeSlots;

use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Coupon;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\BladeThemeV1\App\Models\BlindBag;

use Modules\BladeThemeV1\Services\Payment\OrderHandlerService;
use Modules\BladeThemeV1\Services\Payment\PaymentService;
use Modules\BladeThemeV1\Services\OcrSpaceService;

use Carbon\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductDetail extends Component
{
    use HandleColorTrait,
        HandleSectionCfgTrait,
        HasTimeSlots,
        WithFileUploads,
        ValidationRulesTrait,
        PropertiesProductDetail;
const LOYALTY_DISCOUNT_ENABLED = 0;
    public bool $fromBookingPage = false;
    public $originalTotalAmount = 0;
    public $promoDiscountAmount = 0;
    public $bulkDiscountAmount = 0;
    public $fullBookingDiscount = 0;
    public $hasFullDayBooking = false;
    public $increaseAmount = 0;
    public $blindBag;
    public $customerOrderCount = 0;

    // ✅ ĐÃ CÓ - Properties cho Coupon
    public $couponCode = '';
    public $appliedCoupon = null;
    public $couponDiscountAmount = 0;
    public $couponErrorMessage = '';
    public $couponSuccessMessage = '';

    // OCR properties
    public $cccdFrontText = '';
    public $cccdBackText = '';
    protected $ocrService;

    public $additionalServices = null;
    public array $selectedServices = [];

    public function boot(PaymentService $paymentService, OrderHandlerService $orderHandler, OcrSpaceService $ocrService)
    {
        $this->paymentService = $paymentService;
        $this->orderHandler = $orderHandler;
        $this->ocrService = $ocrService;
    }

    public function mount($slug)
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->slug = $slug;
        $this->product = $this->fetchProduct();
        if ($this->product) {
            $this->productTags = $this->fetchProductTags($this->product->id);
            $this->roomTimeSlots = $this->fetchRoomTimeSlots($this->product->id);
            $this->product = $this->product->load('categories');
            $this->initializeProductData();

            if (Session::has('booking_data') && request()->query('from_book') == 1) {
                $this->fromBookingPage = true;
                $bookingData = Session::get('booking_data');
                Session::forget('booking_data');
                $this->selectedSlots = [];
                $timeSlotsById = collect($this->timeSlots)->keyBy('timeslot_id');

                if (isset($bookingData['selected_slots'])) {
                    foreach ($bookingData['selected_slots'] as $sessionSlot) {
                        $timeSlotDetail = $timeSlotsById->get($sessionSlot['timeslotId']);
                        if ($timeSlotDetail) {
                            $date = Carbon::createFromFormat('d-m-Y', $sessionSlot['date'])->format('Y-m-d');

                            $this->selectedSlots[] = [
                                'key' => "{$date}-{$sessionSlot['timeslotId']}",
                                'date' => $date,
                                'startTime' => $sessionSlot['startTime'],
                                'endTime' => $sessionSlot['endTime'],
                                'price' => $sessionSlot['price'],
                                'originalPrice' => $sessionSlot['originalPrice'] ?? $sessionSlot['price'],
                                'basePrice' => $sessionSlot['basePrice'] ?? $sessionSlot['price'],
                                'increaseAmount' => $sessionSlot['increaseAmount'] ?? 0,
                                'promoDiscount' => $sessionSlot['promoDiscount'] ?? 0,
                                'promoPrice' => 0,
                                'promoType' => '',
                                'timeslotId' => $sessionSlot['timeslotId'],
                                'roomId' => $this->product->id,
                                'roomName' => $this->product->name,
                                'timeslotLabel' => $timeSlotDetail['timeslot_label'],
                                'overNight' => $sessionSlot['overNight'] ?? 0,
                            ];
                        }
                    }
                }
                $this->startTime = $bookingData['start_time'];
                $this->endTime = $bookingData['end_time'];
                $this->updatedSelectedSlots();

                // Lưu trạng thái đã xử lý để khôi phục khi refresh trang
                Session::put('booking_detail_state', [
                    'slug'           => $this->slug,
                    'selected_slots' => $this->selectedSlots,
                    'start_time'     => $this->startTime,
                    'end_time'       => $this->endTime,
                ]);

            } elseif (
                Session::has('booking_detail_state') &&
                Session::get('booking_detail_state.slug') === $this->slug
            ) {
                // Khôi phục trạng thái sau khi refresh trang
                // Re-validate từng slot: chỉ giữ lại slot có timeslotId tồn tại trong DB
                $this->fromBookingPage = true;
                $state = Session::get('booking_detail_state');
                $validTimeslotIds = collect($this->timeSlots)->pluck('timeslot_id')->flip();
                $this->selectedSlots = collect($state['selected_slots'] ?? [])
                    ->filter(fn($s) => isset($s['timeslotId']) && $validTimeslotIds->has($s['timeslotId']))
                    ->values()
                    ->toArray();
                $this->startTime = $state['start_time'] ?? null;
                $this->endTime   = $state['end_time']   ?? null;
                $this->updatedSelectedSlots();
            }
        }
        $this->blindBag = BlindBag::first();
        $this->additionalServices = $this->product
            ? $this->product->additionalServices()->where('additional_services.is_active', 1)->get()
            : collect();
        $this->selectedServices = [];
    }

    /**
     * Gọi từ Alpine.js khi phát hiện auth token trong localStorage.
     * Verify token server-side, set buyer info từ DB, đánh dấu isAuthUser.
     * Nếu token rỗng (logout) → clear trạng thái auth.
     */
    public function prefillFromAuth(string $token): void
    {
        if (empty($token)) {
            if ($this->isAuthUser) {
                $this->buyerName  = '';
                $this->buyerPhone = '';
                $this->isAuthUser = false;
                $this->authUserId = null;
            }
            return;
        }

        try {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if (!$pat || $pat->tokenable_type !== \App\Models\User::class) {
                return;
            }

            /** @var \App\Models\User $user */
            $user = $pat->tokenable;
            if (!$user) {
                return;
            }

            // DB lưu phone dạng 84xxxxxxxxx → hiển thị 0xxxxxxxxx
            $phone = $user->phone ?? '';
            if (str_starts_with($phone, '84') && strlen($phone) >= 11) {
                $phone = '0' . substr($phone, 2);
            }

            $this->buyerName  = $user->fullname ?? '';
            $this->buyerPhone = $phone;
            $this->isAuthUser = true;
            $this->authUserId = $user->id;
        } catch (\Throwable) {
            // Silent fail — user tiếp tục với form thường
        }
    }

    protected function initializeProductData()
    {
        $this->mediaSecond = $this->product->getMedia('Thư viện');
        $this->shortDescription = html_entity_decode(strip_tags($this->product->short_description));
        $this->categories = $this->product->category_names;
        $this->dates = $this->generateDateRange();
        $this->timeSlots = $this->prepareTimeSlots($this->product);
        $this->fetchDateStatusOrder($this->product->id);
        $this->bookingStyle = (int)($this->product->styles ?? 1);
    }

    public function applyCoupon()
    {
        $this->resetCouponMessages();

        $isStyle2 = ((int) ($this->bookingStyle ?? 1)) === 2;

        // Validate input
        if (empty($this->couponCode)) {
            $this->couponErrorMessage = 'Vui lòng nhập mã giảm giá';
            return;
        }

        if ($isStyle2) {
            if (empty($this->startTime) || empty($this->endTime)) {
                $this->couponErrorMessage = 'Vui lòng chọn ngày nhận và trả phòng trước khi áp dụng mã giảm giá';
                return;
            }
        } else {
            if (empty($this->selectedSlots)) {
                $this->couponErrorMessage = 'Vui lòng chọn khung giờ trước khi áp dụng mã giảm giá';
                return;
            }
        }

        // Tìm coupon
        $coupon = Coupon::where('code', strtoupper($this->couponCode))
            ->where('is_active', true)
            ->first();

        if (!$coupon) {
            $this->couponErrorMessage = 'Mã giảm giá không tồn tại hoặc đã hết hạn';
            return;
        }

        // Kiểm tra thời gian
        $now = now();
        if ($coupon->start_at && $now->lt($coupon->start_at)) {
            $this->couponErrorMessage = 'Mã giảm giá chưa bắt đầu';
            return;
        }
        if ($coupon->end_at && $now->gt($coupon->end_at)) {
            $this->couponErrorMessage = 'Mã giảm giá đã hết hạn';
            return;
        }

        // Kiểm tra usage limit
        if ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
            $this->couponErrorMessage = 'Mã giảm giá đã hết lượt sử dụng';
            return;
        }

        // Kiểm tra áp dụng cho slot
        $applicableSlots = $this->getApplicableSlots($coupon);
        if (empty($applicableSlots)) {
            $this->couponErrorMessage = $isStyle2
                ? 'Mã giảm giá không áp dụng cho khoảng ngày đã chọn'
                : 'Mã giảm giá không áp dụng cho các khung giờ đã chọn';
            return;
        }

        // Tính tổng giá trị các slot áp dụng được (sau promotion)
        $applicableAmount = collect($applicableSlots)->sum('price');

        // Kiểm tra min_order_value
        if ($coupon->min_order_value && $applicableAmount < $coupon->min_order_value) {
            $this->couponErrorMessage = sprintf(
                'Giá trị đơn hàng tối thiểu là %s VNĐ',
                number_format($coupon->min_order_value, 0, ',', '.')
            );
            return;
        }

        // Áp dụng coupon thành công
        $this->appliedCoupon = $coupon;
        $this->couponSuccessMessage = sprintf(
            'Áp dụng mã giảm giá "%s" thành công!',
            $coupon->code
        );

        // Tính lại tổng tiền
        if ($isStyle2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }

        // Dispatch event để thông báo
        $this->dispatch('notify', [
            'message' => $this->couponSuccessMessage,
            'type' => 'success',
        ]);
    }

    /**
     * Lấy các slot áp dụng được coupon
     */
    protected function getApplicableSlots($coupon)
    {
        if (((int) ($this->bookingStyle ?? 1)) === 2) {
            if (empty($this->startTime) || empty($this->endTime) || !$this->product) {
                return [];
            }

            $checkin = Carbon::parse($this->startTime)->startOfDay();
            $checkout = Carbon::parse($this->endTime)->startOfDay();
            if ($checkin->gte($checkout)) {
                return [];
            }

            $dayPriceMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];
            $dayPrices = [];
            foreach ($this->product->day_prices ?? [] as $k => $v) {
                if (isset($dayPriceMap[$k]) && (int) $v > 0) {
                    $dayPrices[$dayPriceMap[$k]] = (int) $v;
                }
            }

            $basePrice = (float) ($this->product->price ?? 0);
            $productDisc = (float) ($this->product->discount ?? 0);
            $effectiveBase = $productDisc > 0 ? round($basePrice * (1 - $productDisc / 100)) : $basePrice;

            $this->product->loadMissing(['roomTimeSlots.timeSlot', 'roomTimeSlots.promotions']);
            $dateSlotMap = [];
            foreach ($this->product->roomTimeSlots as $rts) {
                if ($rts->timeSlot && ($rts->timeSlot->type ?? '') === 'date' && !empty($rts->timeSlot->label)) {
                    $dateSlotMap[$rts->timeSlot->label] = $rts;
                }
            }

            $result = [];
            $nowDt = now();
            for ($day = $checkin->copy(); $day->lt($checkout); $day->addDay()) {
                $iso = $day->format('Y-m-d');
                $slot = $dateSlotMap[$iso] ?? null;

                if (!$slot) {
                    $slot = new RoomTimeSlot([
                        'room_id' => $this->product->id,
                        'price' => null,
                    ]);
                    $slot->setRelation('promotions', collect());
                }

                if (!$coupon->isApplicableToSlot($slot)) {
                    continue;
                }

                $nightBase = $slot->price !== null
                    ? (float) $slot->price
                    : (float) ($dayPrices[$day->dayOfWeek] ?? $effectiveBase);

                $nightAfterPromo = $nightBase;
                foreach ($slot->promotions as $promo) {
                    if (!$promo->is_active) continue;
                    if ($promo->start_at && $promo->start_at > $nowDt) continue;
                    if ($promo->end_at   && $promo->end_at   < $nowDt) continue;
                    $v = (float) $promo->value;
                    switch ($promo->type) {
                        case 'percentage':          $nightAfterPromo = round($nightAfterPromo * (1 - $v / 100)); break;
                        case 'fixed':               $nightAfterPromo = max(0, $nightAfterPromo - $v); break;
                        case 'increase_percentage': $nightAfterPromo = round($nightAfterPromo * (1 + $v / 100)); break;
                        case 'increase_fixed':      $nightAfterPromo += $v; break;
                    }
                }

                $result[] = [
                    'roomId' => $this->product->id,
                    'timeslotId' => $slot->timeslot_id,
                    'date' => $iso,
                    'price' => (float) $nightAfterPromo,
                ];
            }

            return $result;
        }

        return collect($this->selectedSlots)->filter(function ($slot) use ($coupon) {
            // Tìm RoomTimeSlot tương ứng
            $roomTimeSlot = RoomTimeSlot::where('room_id', $slot['roomId'])
                ->where('timeslot_id', $slot['timeslotId'])
                ->first();

            if (!$roomTimeSlot) {
                return false;
            }

            return $coupon->isApplicableToSlot($roomTimeSlot);
        })->values()->all();
    }

    /**
     * Chọn hình thức thanh toán (deposit / full)
     */
    public function selectPaymentOption(string $option): void
    {
        $this->paymentOption = in_array($option, ['deposit', 'full']) ? $option : 'deposit';
    }

    /**
     * Xóa coupon đã áp dụng
     */
    public function removeCoupon()
    {
        $this->appliedCoupon = null;
        $this->couponCode = '';
        $this->resetCouponMessages();

        if (((int) ($this->bookingStyle ?? 1)) === 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }

        $this->dispatch('notify', [
            'message' => 'Đã xóa mã giảm giá',
            'type' => 'info',
        ]);
    }

    protected function getLoyaltyDiscountRate(): float
    {
        if (!self::LOYALTY_DISCOUNT_ENABLED) return 0;
        $this->customerOrderCount = 0;

        if (empty($this->buyerPhone)) return 0;

        $count = Order::where('buyer_phone', $this->buyerPhone)
            ->whereIn('status', ['paid', 'completed'])
            ->count();

        $this->customerOrderCount = $count;

        if ($count === 1) return 0.10;
        if ($count >= 2)  return 0.05;

        return 0;
    }

    public function incrementService(int $serviceId): void
    {
        $this->selectedServices[$serviceId] = ($this->selectedServices[$serviceId] ?? 0) + 1;
        if ($this->bookingStyle == 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }
    }

    public function decrementService(int $serviceId): void
    {
        if (($this->selectedServices[$serviceId] ?? 0) > 0) {
            $this->selectedServices[$serviceId]--;
        }
        if (($this->selectedServices[$serviceId] ?? 0) === 0) {
            unset($this->selectedServices[$serviceId]);
        }
        if ($this->bookingStyle == 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }
    }

    /**
     * Reset thông báo coupon
     */
    protected function resetCouponMessages()
    {
        $this->couponErrorMessage = '';
        $this->couponSuccessMessage = '';
    }

    // =========================================================

    public function updatedSelectedSlots()
    {
        if (is_null($this->additionalServices) || $this->additionalServices->isEmpty()) {
            $this->additionalServices = $this->product
                ? $this->product->additionalServices()->where('additional_services.is_active', 1)->get()
                : collect();
        }
        $this->isCalculating = true;
        $this->originalTotalAmount = 0;
        $this->promoDiscountAmount = 0;
        $this->bulkDiscountAmount = 0;
        $this->couponDiscountAmount = 0;
        $this->fullBookingDiscount = 0;
        $this->hasFullDayBooking = false;
        $this->totalAmount = 0;
        $this->extraFee = 0;
        $this->increaseAmount = 0;

        if (empty($this->selectedSlots)) {
            $this->startTime = '';
            $this->endTime = '';
            $this->isCalculating = false;
            return;
        }

        $sortedSlots = collect($this->selectedSlots)->sortBy(function ($slot) {
            return Carbon::parse("{$slot['date']} {$slot['startTime']}");
        })->values()->toArray();

        $this->startTime = Carbon::parse("{$sortedSlots[0]['date']} {$sortedSlots[0]['startTime']}")->format('Y-m-d H:i');
        $lastSlot = $sortedSlots[count($sortedSlots) - 1];
        $endDateTime = Carbon::parse("{$lastSlot['date']} {$lastSlot['endTime']}");

        if (isset($lastSlot['overNight']) && $lastSlot['overNight'] == 1) {
            $endDateTime->addDay();
        }

        $this->endTime = $endDateTime->format('Y-m-d H:i');

        // ========== ✅ KIỂM TRA FULL BOOKING TRƯỚC ==========
        $this->hasFullDayBooking = $this->checkFullDayBooking();

        // Nhóm slots theo ngày để xác định ngày nào full booking
        $slotsByDate = collect($this->selectedSlots)->groupBy('date');
        $fullDayDates = [];

        if ($this->hasFullDayBooking) {
            $totalTimeslots = count($this->timeSlots);
            foreach ($slotsByDate as $date => $slots) {
                if (count($slots) === $totalTimeslots) {
                    $fullDayDates[] = $date;
                }
            }
        }

        $totalOriginal = 0;
        $totalAfterPromo = 0;
        $totalIncrease = 0;

        // Tính tổng giá, BỎ QUA promotion giảm giá cho ngày full booking
        foreach ($this->selectedSlots as $slot) {
            $basePrice = floatval($slot['basePrice'] ?? $slot['originalPrice'] ?? $slot['price']);
            $increaseAmountPerSlot = floatval($slot['increaseAmount'] ?? 0);
            $promoDiscount = floatval($slot['promoDiscount'] ?? 0);

            // Giá sau khi tăng = giá gốc + phụ thu
            $priceAfterIncrease = $basePrice + $increaseAmountPerSlot;

            // Giá cuối = giá sau tăng - discount (nếu không phải full booking)
            $finalPrice = $priceAfterIncrease;
            if (!in_array($slot['date'], $fullDayDates)) {
                $finalPrice -= $promoDiscount;
            }

            $totalOriginal += $basePrice;
            $totalAfterPromo += $finalPrice;
            $totalIncrease += $increaseAmountPerSlot;
        }

        $this->originalTotalAmount = $totalOriginal;
        $this->increaseAmount = $totalIncrease;

        // Tính promo discount (tổng discount đã trừ)
        $expectedTotal = $totalOriginal + $totalIncrease;
        $this->promoDiscountAmount = $expectedTotal - $totalAfterPromo;

        // ========== XỬ LÝ FULL BOOKING ==========
        if ($this->hasFullDayBooking && $this->product && $this->product->full_booking_discount) {
            $this->fullBookingDiscount = $this->calculateFullBookingDiscount(
                $totalAfterPromo,
                $this->product->full_booking_discount
            );
            $totalAfterPromo -= $this->fullBookingDiscount;

            // ❌ KHÔNG áp dụng bulk discount khi có full booking
            $this->bulkDiscountAmount = 0;

        } else {
            // ========== GIẢM GIÁ BULK (chỉ khi KHÔNG full booking) ==========
            $slotCount = count($this->selectedSlots);
            $bulkDiscountRate = 0;

            $rules = $this->product->bulk_discount_rules ?? [];
            if (!empty($rules)) {
                $matched = collect($rules)
                    ->filter(fn($r) => $slotCount >= (int)($r['slots'] ?? 0))
                    ->sortByDesc('slots')
                    ->first();

                if ($matched) {
                    $bulkDiscountRate = (float)($matched['discount'] ?? 0) / 100;
                }
            }

            if ($bulkDiscountRate > 0) {
                $this->bulkDiscountAmount  = $totalAfterPromo * $bulkDiscountRate;
                $totalAfterPromo          -= $this->bulkDiscountAmount;
            } else {
                $this->bulkDiscountAmount = 0;
            }

            $loyaltyRate = $this->getLoyaltyDiscountRate();
            if ($loyaltyRate > 0) {
                $loyaltyDiscount           = $totalAfterPromo * $loyaltyRate;
                $this->bulkDiscountAmount += $loyaltyDiscount;
                $totalAfterPromo          -= $loyaltyDiscount;
            }
        }

        // ========== COUPON: luôn áp dụng được, kể cả khi full booking ==========
        // Tính trên $totalAfterPromo (giá sau các discount trước đó) — không dùng giá gốc
        if ($this->appliedCoupon) {
            $this->couponDiscountAmount = $this->appliedCoupon->calculateDiscount($totalAfterPromo);
            $totalAfterPromo           -= $this->couponDiscountAmount;
        }

        // Tính phụ phí theo cấu hình phòng
        $cfg1 = $this->product ? ($this->product->room_config ?? []) : [];
        $this->extraFee = $this->guests > (int)($cfg1['max_free_guests'] ?? 2)
            ? ($this->guests - (int)($cfg1['max_free_guests'] ?? 2)) * (int)($cfg1['extra_guest_fee'] ?? 50000)
            : 0;

        // ✅ ĐỔI THÀNH
        $serviceTotal = 0;
        if (!empty($this->selectedServices) && $this->additionalServices) {
            foreach ($this->selectedServices as $id => $qty) {
                $service = $this->additionalServices->firstWhere('id', $id);
                if ($service && $qty > 0) {
                    $serviceTotal += $service->price * $qty;
                }
            }
        }
        $this->totalAmount = max(0, $totalAfterPromo + $this->extraFee + $serviceTotal);
        if (is_null($this->additionalServices) || $this->additionalServices->isEmpty()) {
            $this->additionalServices = $this->product
                ? $this->product->additionalServices()->where('additional_services.is_active', 1)->get()
                : collect();
        }
        $this->isCalculating = false;
    }

    /**
     * Kiểm tra xem có đặt full phòng trong 1 ngày không
     */
    protected function checkFullDayBooking()
    {
        if (empty($this->selectedSlots) || !$this->product) {
            return false;
        }

        // Chỉ kích hoạt full booking mode khi phòng có cấu hình giảm giá full
        if (empty($this->product->full_booking_discount)) {
            return false;
        }

        // Lấy tổng số timeslot của phòng
        $totalTimeslots = count($this->timeSlots);
        if ($totalTimeslots === 0) {
            return false;
        }

        // Nhóm các slot đã chọn theo ngày
        $slotsByDate = collect($this->selectedSlots)->groupBy('date');

        // Kiểm tra xem có ngày nào đặt đủ số khung giờ không
        foreach ($slotsByDate as $date => $slots) {
            if (count($slots) === $totalTimeslots) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tính giảm giá full booking
     */
    protected function calculateFullBookingDiscount($totalPrice, $fullBookingDiscount)
    {
        if (empty($fullBookingDiscount)) {
            return 0;
        }

        // Kiểm tra xem là % hay số tiền cố định
        if (str_contains($fullBookingDiscount, '%')) {
            $percentage = (float) str_replace('%', '', $fullBookingDiscount);
            return $totalPrice * ($percentage / 100);
        } else {
            return (float) str_replace(['.', ','], '', $fullBookingDiscount);
        }
    }

    public function updatedCccdFront()
    {
        if ($this->cccd_front && $this->ocrService && $this->ocrService->isConfigured()) {
            try {
                $tempPath = $this->cccd_front->getRealPath();
                $this->cccdFrontText = $this->ocrService->extractTextFromImage($tempPath);
                Log::info('CCCD Front OCR completed (OCR.space)', [
                    'text_length' => strlen($this->cccdFrontText)
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to process CCCD front OCR (OCR.space)', [
                    'error' => $e->getMessage()
                ]);
                $this->cccdFrontText = '';
            }
        }
    }

    public function updatedCccdBack()
    {
        $this->cccdBackText = '';
    }

    public function updatedStartTime()
    {
        if ($this->bookingStyle == 2) $this->calculateDateRangeTotal();
    }

    public function updatedEndTime()
    {
        if ($this->bookingStyle == 2) $this->calculateDateRangeTotal();
    }

    public function updatedGuests()
    {
        if ($this->bookingStyle == 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }
    }

    protected function calculateDateRangeTotal(): void
    {
        $this->originalTotalAmount = 0;
        $this->promoDiscountAmount = 0;
        $this->bulkDiscountAmount = 0;
        $this->fullBookingDiscount = 0;
        $this->hasFullDayBooking = false;
        $this->couponDiscountAmount = 0;

        if (empty($this->startTime) || empty($this->endTime)) {
            $this->totalAmount = 0;
            $this->style2CheckinTime = '14:00';
            $this->style2CheckoutTime = '12:00';
            return;
        }
        $checkin  = Carbon::parse($this->startTime)->startOfDay();
        $checkout = Carbon::parse($this->endTime)->startOfDay();
        if ($checkin->gte($checkout)) {
            $this->totalAmount = 0;
            $this->style2CheckinTime = '14:00';
            $this->style2CheckoutTime = '12:00';
            return;
        }

        $dayPriceMap = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>0];
        $dayPrices = [];
        foreach ($this->product->day_prices ?? [] as $k => $v) {
            if (isset($dayPriceMap[$k]) && (int)$v > 0) $dayPrices[$dayPriceMap[$k]] = (int)$v;
        }

        $dateConfigs = [];
        $promoEffects = []; // ['type' => ..., 'value' => ...][]
        $nowDt = now();
        $this->product->load(['roomTimeSlots.timeSlot', 'roomTimeSlots.promotions']);
        foreach ($this->product->roomTimeSlots as $rts) {
            if ($rts->timeSlot && ($rts->timeSlot->type ?? '') === 'date' && !empty($rts->timeSlot->label)) {
                $dk = $rts->timeSlot->label;
                $dateConfigs[$dk] = [
                    'price' => $rts->price !== null ? (float) $rts->price : null,
                    'checkin' => $rts->checkin,
                    'checkout' => $rts->checkout,
                ];
                foreach ($rts->promotions as $promo) {
                    if ($promo->is_active
                        && (!$promo->start_at || $promo->start_at <= $nowDt)
                        && (!$promo->end_at   || $promo->end_at   >= $nowDt)
                    ) {
                        $promoEffects[$dk][] = ['type' => $promo->type, 'value' => (float) $promo->value];
                    }
                }
            }
        }

        $basePrice   = (float)($this->product->price ?? 0);
        $productDisc = (float)($this->product->discount ?? 0);
        $effectiveBase = $productDisc > 0 ? round($basePrice * (1 - $productDisc / 100)) : $basePrice;

        $totalBeforePromo = 0;
        $totalAfterPromo  = 0;
        $totalIncrease    = 0;
        $totalDiscount    = 0;
        $current = $checkin->copy();
        while ($current->lt($checkout)) {
            $iso = $current->format('Y-m-d');
            $nightBase = $effectiveBase;

            if (isset($dateConfigs[$iso]) && $dateConfigs[$iso]['price'] !== null) {
                $nightBase = (float) $dateConfigs[$iso]['price'];
            } elseif (isset($dayPrices[$current->dayOfWeek])) {
                $nightBase = (float) $dayPrices[$current->dayOfWeek];
            }

            $nightAfterPromo = $nightBase;
            $nightIncrease   = 0;
            $nightDiscount   = 0;
            foreach ($promoEffects[$iso] ?? [] as $effect) {
                $v = $effect['value'];
                switch ($effect['type']) {
                    case 'percentage':
                        $disc = round($nightAfterPromo * $v / 100);
                        $nightDiscount  += $disc;
                        $nightAfterPromo -= $disc;
                        break;
                    case 'fixed':
                        $disc = min((float) $nightAfterPromo, (float) $v);
                        $nightDiscount  += $disc;
                        $nightAfterPromo = max(0, $nightAfterPromo - $v);
                        break;
                    case 'increase_percentage':
                        $inc = round($nightAfterPromo * $v / 100);
                        $nightIncrease   += $inc;
                        $nightAfterPromo  = round($nightAfterPromo * (1 + $v / 100));
                        break;
                    case 'increase_fixed':
                        $nightIncrease   += $v;
                        $nightAfterPromo  += $v;
                        break;
                }
            }

            $totalBeforePromo += $nightBase;
            $totalAfterPromo  += $nightAfterPromo;
            $totalIncrease    += $nightIncrease;
            $totalDiscount    += $nightDiscount;
            $current->addDay();
        }

        $this->originalTotalAmount = $totalBeforePromo;
        $this->increaseAmount      = $totalIncrease;
        $this->promoDiscountAmount = $totalDiscount;

        if ($this->appliedCoupon) {
            $applicableSlots = $this->getApplicableSlots($this->appliedCoupon);
            $applicableAmount = collect($applicableSlots)->sum('price');
            $this->couponDiscountAmount = $this->appliedCoupon->calculateDiscount((float) $applicableAmount);
            $totalAfterPromo -= $this->couponDiscountAmount;
        }

        $resolvedCheckin = $dateConfigs[$checkin->format('Y-m-d')]['checkin'] ?? null;
        $lastNightDate = $checkout->copy()->subDay()->format('Y-m-d');
        $resolvedCheckout = $dateConfigs[$lastNightDate]['checkout'] ?? null;
        $this->style2CheckinTime = !empty($resolvedCheckin) ? substr((string) $resolvedCheckin, 0, 5) : '14:00';
        $this->style2CheckoutTime = !empty($resolvedCheckout) ? substr((string) $resolvedCheckout, 0, 5) : '12:00';

        $cfg2 = $this->product ? ($this->product->room_config ?? []) : [];
        $this->extraFee = $this->guests > (int)($cfg2['max_free_guests'] ?? 2)
            ? ($this->guests - (int)($cfg2['max_free_guests'] ?? 2)) * (int)($cfg2['extra_guest_fee'] ?? 50000)
            : 0;

        $serviceTotal = 0;
        if (!empty($this->selectedServices) && $this->additionalServices) {
            foreach ($this->selectedServices as $id => $qty) {
                $service = $this->additionalServices->firstWhere('id', $id);
                if ($service && $qty > 0) {
                    $serviceTotal += $service->price * $qty;
                }
            }
        }
        $this->totalAmount = max(0, $totalAfterPromo + $this->extraFee + $serviceTotal);
    }

    public function datPhong()
    {
        // Kiểm tra riêng chính sách hoàn tiền để hiển thị lỗi cụ thể
        if (!$this->acceptRefundPolicy) {
            $this->addError('acceptRefundPolicy', 'Vui lòng đồng ý với chính sách hoàn tiền.');
            $this->dispatch('notify', [
                'message' => 'Vui lòng đồng ý với chính sách hoàn tiền.',
                'type' => 'error',
            ]);
            return;
        }

        $requiredFields = [
            'buyerName',
            'buyerPhone',
            'guests',
            'cccd_front',
            'cccd_back',
            'accept1',
        ];

        $hasSelectedSlots = !empty($this->selectedSlots);
        $hasDateRange     = $this->bookingStyle == 2 && !empty($this->startTime) && !empty($this->endTime);
        $hasBooking       = $hasSelectedSlots || $hasDateRange;
        $hasAllFields     = collect($requiredFields)->every(fn($field) => !empty($this->{$field}));

        if (!$hasAllFields && !$hasBooking) {
            $this->validateAndNotify();
            return;
        }

        if ($hasAllFields && !$hasBooking) {
            $this->dispatch('notify', [
                'message' => $this->bookingStyle == 2
                    ? 'Vui lòng chọn ngày nhận & trả phòng.'
                    : 'Vui lòng chọn khung giờ.',
                'type' => 'error',
            ]);
            return;
        }

        if (!$hasAllFields && $hasBooking) {
            $this->validateAndNotify();
            return;
        }

        $this->validateAndNotify();
        if ($this->bookingStyle == 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->updatedSelectedSlots();
        }
        // Thành:
        if (self::LOYALTY_DISCOUNT_ENABLED) {
            $orderCount = \Modules\Payment\Entities\Order::where('buyer_phone', $this->buyerPhone)
                ->whereIn('status', ['paid', 'completed'])
                ->count();
            $this->customerOrderCount = $orderCount;
            if ($orderCount >= 1) {
                $this->dispatch('open-loyalty-modal', count: $orderCount);
                return;
            }
        }
        // Kiểm tra xung đột trước khi mở modal xác nhận
        if ($this->bookingStyle == 1 && !empty($this->selectedSlots)) {
            $conflict = $this->checkSlotConflicts();
            if ($conflict) {
                $statusLabel = match($conflict['order_status']) {
                    'paid'      => 'đã thanh toán',
                    'confirmed' => 'đã xác nhận',
                    'shipped'   => 'đang xử lý',
                    default     => 'đang chờ thanh toán',
                };
                $this->dispatch('notify', [
                    'message' => "Khung giờ {$conflict['slot_label']} ngày {$conflict['date']} đã được đặt bởi khách hàng {$conflict['buyer_name']} ({$conflict['masked_phone']}) - {$statusLabel}.",
                    'type'    => 'error',
                ]);
                return;
            }
        }

        if ($this->bookingStyle == 2) {
            $conflict = $this->checkDateRangeConflicts();
            if ($conflict) {
                $statusLabel = match($conflict['order_status']) {
                    'paid'      => 'đã thanh toán',
                    'deposit'   => 'đặt cọc',
                    'completed' => 'đã hoàn thành',
                    'confirmed' => 'đã xác nhận',
                    default     => 'đang chờ thanh toán',
                };
                $this->dispatch('notify', [
                    'message' => "Ngày {$conflict['checkin']} – {$conflict['checkout']} đã được đặt bởi {$conflict['buyer_name']} ({$conflict['masked_phone']}) - {$statusLabel}. Vui lòng chọn ngày khác.",
                    'type'    => 'error',
                ]);
                return;
            }
        }

        $this->dispatch('open-booking-modal');
    }

    /**
     * Re-fetch giá từ DB cho style 1 để chống price manipulation từ client.
     * Ghi đè $this->selectedSlots với giá thực từ RoomTimeSlot + promotions.
     * Dùng PromotionCalculator để đảm bảo logic giống API 100%.
     */
    protected function recalculateSlotPricesFromDB(): void
    {
        if (empty($this->selectedSlots) || !$this->product) {
            return;
        }

        $this->product->loadMissing(['roomTimeSlots.timeSlot', 'roomTimeSlots.promotions']);

        $basePrice     = (float) ($this->product->price ?? 0);
        $productDisc   = (float) ($this->product->discount ?? 0);
        $effectiveBase = $productDisc > 0 ? round($basePrice * (1 - $productDisc / 100)) : $basePrice;

        $calculator = new \App\Services\PromotionCalculator();
        $rtsMap     = collect($this->product->roomTimeSlots)->keyBy('timeslot_id');
        $sanitized  = [];

        foreach ($this->selectedSlots as $slot) {
            $timeslotId  = $slot['timeslotId'] ?? null;
            $bookingDate = $slot['date'] ?? null;
            $rts         = $timeslotId ? $rtsMap->get($timeslotId) : null;

            $dbBasePrice = ($rts && $rts->price !== null) ? (float) $rts->price : $effectiveBase;

            $increaseAmount  = 0;
            $promoDiscount   = 0;
            $priceAfterPromo = $dbBasePrice;

            if ($rts && $bookingDate && $rts->timeSlot) {
                // Tạm gán price gốc để calculator dùng đúng base
                $rts->price = $dbBasePrice;

                $result          = $calculator->calculate($rts, $bookingDate);
                $increaseAmount  = $result['increase_amount'];
                $promoDiscount   = $result['promo_discount'];
                $priceAfterPromo = $result['final_price'];
            }

            $slot['basePrice']      = $dbBasePrice;
            $slot['originalPrice']  = $dbBasePrice;
            $slot['price']          = $priceAfterPromo;
            $slot['increaseAmount'] = $increaseAmount;
            $slot['promoDiscount']  = $promoDiscount;
            $sanitized[] = $slot;
        }

        $this->selectedSlots = $sanitized;
    }

public function confirmBooking()
{
    try {
        // Upload file TRƯỚC transaction (không thể rollback file)
        $frontPath = $this->cccd_front ? $this->cccd_front->store('cccd/front', 'public') : null;
        $backPath  = $this->cccd_back  ? $this->cccd_back->store('cccd/back', 'public')   : null;

        $roomConfig   = $this->product ? ($this->product->room_config ?? []) : [];
        $maxFreeGuests = (int) ($roomConfig['max_free_guests'] ?? 2);
        $extraGuestFee = (int) ($roomConfig['extra_guest_fee'] ?? 50000);
        $extraFee     = $this->guests > $maxFreeGuests ? ($this->guests - $maxFreeGuests) * $extraGuestFee : 0;
        $categoryId   = $this->getBranchCategoryId();

        // Tính lại giá từ DB để chống price manipulation từ phía client
        if ($this->bookingStyle == 2) {
            $this->calculateDateRangeTotal();
        } else {
            $this->recalculateSlotPricesFromDB();
            $this->updatedSelectedSlots();
        }

        $orderTotal   = $this->totalAmount ?? 0;
        $noteForAdmin = '';

        if ($this->ocrService && $this->ocrService->isConfigured() && !empty($this->cccdFrontText)) {
            $noteForAdmin = $this->ocrService->formatCccdInfo($this->cccdFrontText);
        }

        // Security: nếu đặt phòng với tài khoản đã xác thực, re-fetch dữ liệu từ DB
        // để chống price/identity manipulation qua Livewire snapshot.
        $verifiedBuyerName  = $this->buyerName;
        $verifiedBuyerPhone = $this->buyerPhone;
        $verifiedUserId     = null;

        if ($this->isAuthUser && $this->authUserId) {
            $authUser = \App\Models\User::find($this->authUserId);
            if ($authUser) {
                $verifiedBuyerName = $authUser->fullname ?? $this->buyerName;
                $rawPhone = $authUser->phone ?? '';
                if (str_starts_with($rawPhone, '84') && strlen($rawPhone) >= 11) {
                    $rawPhone = '0' . substr($rawPhone, 2);
                }
                $verifiedBuyerPhone = $rawPhone ?: $this->buyerPhone;
                $verifiedUserId     = $authUser->id;
            }
        }

        // =====================================================================
        // Tính tiền cọc theo cấu hình phòng (style=2)
        // - 1 đêm  → % cọc = deposit_1_night  (mặc định 100%)
        // - ≥2 đêm → % cọc = deposit_multi_night (mặc định 50%)
        // =====================================================================
        $depositPercent = 100; // mặc định thanh toán đủ
        $fullAmount     = $orderTotal;

        if ($this->bookingStyle == 2 && $this->product) {
            $checkin  = \Carbon\Carbon::parse($this->startTime)->startOfDay();
            $checkout = \Carbon\Carbon::parse($this->endTime)->startOfDay();
            $nights   = $checkin->diffInDays($checkout);
            $minNights = (int) ($this->product->deposit_min_nights ?? 2);

            // Nếu user chọn "Thanh toán 100%", bỏ qua cọc
            if ($this->paymentOption === 'full') {
                $depositPercent = 100;
            } elseif ($minNights > 0 && $nights >= $minNights) {
                // Đủ ngưỡng → áp dụng cọc
                $depositPercent = (int) ($this->product->deposit_multi_night ?? 50);
            } else {
                // Chưa đủ ngưỡng hoặc min_nights = 0 → thanh toán 100%
                $depositPercent = 100;
            }
        }

        $paymentAmount = ($depositPercent >= 100)
            ? $orderTotal
            : (int) round($fullAmount * $depositPercent / 100);

        // =====================================================================
        // TRANSACTION: conflict check + order creation trong cùng 1 transaction
        // lockForUpdate ngăn 2 request đồng thời cùng tạo đơn trùng khung giờ
        // =====================================================================
        $order = DB::transaction(function () use ($frontPath, $backPath, $extraFee, $categoryId, $orderTotal, $noteForAdmin, $paymentAmount, $depositPercent, $fullAmount, $verifiedBuyerName, $verifiedBuyerPhone, $verifiedUserId) {

            // --- Kiểm tra xung đột (style 1) ---
            if ($this->bookingStyle == 1 && !empty($this->selectedSlots)) {
                $conflict = $this->checkSlotConflicts(lockForUpdate: true);
                if ($conflict) {
                    throw new \RuntimeException('SLOT_CONFLICT:' . json_encode($conflict));
                }
            }

            // --- Kiểm tra xung đột (style 2) ---
            if ($this->bookingStyle == 2) {
                $conflict = $this->checkDateRangeConflicts(lockForUpdate: true);
                if ($conflict) {
                    throw new \RuntimeException('DATE_CONFLICT:' . json_encode($conflict));
                }
            }

            // --- Tạo đơn hàng ---
            $order = Order::create([
                'order_code'      => time() . rand(1000, 9999),
                'buyer_name'      => $verifiedBuyerName,
                'buyer_phone'     => $verifiedBuyerPhone,
                'user_id'         => $verifiedUserId,
                'amount'          => $paymentAmount,
                'full_amount'     => $fullAmount,
                'deposit_percent' => $depositPercent < 100 ? $depositPercent : null,
                // Đơn cọc (style=2 + deposit_percent < 100) → khởi đầu với status 'deposit'
                'status'         => ($depositPercent < 100) ? 'deposit' : 'pending',
                'payment_method' => $this->paymentMethod ?? 'payos',
                'description'    => !empty($this->note) ? $this->note : 'Đặt phòng - ' . $this->product->name,
                'cccd_front'     => $frontPath,
                'cccd_back'      => $backPath,
                'guest_count'    => $this->guests,
                'category_id'    => $categoryId,
                'coupon_code'    => $this->appliedCoupon ? $this->appliedCoupon->code : null,
                'coupon_discount'=> $this->couponDiscountAmount,
                'note_for_admin' => $noteForAdmin,
            ]);

            // ✅ 1. Lưu ORDER ITEMS
            if ($this->bookingStyle == 2) {
                $checkinDate = Carbon::parse($this->startTime)->startOfDay();
                $checkoutDate = Carbon::parse($this->endTime)->startOfDay();

                $this->product->loadMissing(['roomTimeSlots.timeSlot']);
                $dateSlots = collect($this->product->roomTimeSlots)->filter(function ($slot) {
                    return $slot->timeSlot && ($slot->timeSlot->type ?? '') === 'date' && !empty($slot->timeSlot->label);
                });

                $checkinSlot = $dateSlots->firstWhere('timeSlot.label', $checkinDate->format('Y-m-d'));
                $lastNightDate = $checkoutDate->copy()->subDay()->format('Y-m-d');
                $checkoutSlot = $dateSlots->firstWhere('timeSlot.label', $lastNightDate);

                $checkinTimeStr = !empty($checkinSlot?->checkin) ? substr((string) $checkinSlot->checkin, 0, 8) : '14:00:00';
                $checkoutTimeStr = !empty($checkoutSlot?->checkout) ? substr((string) $checkoutSlot->checkout, 0, 8) : '12:00:00';

                if (strlen($checkinTimeStr) === 5) {
                    $checkinTimeStr .= ':00';
                }
                if (strlen($checkoutTimeStr) === 5) {
                    $checkoutTimeStr .= ':00';
                }

                $checkinDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $checkinDate->format('Y-m-d') . ' ' . $checkinTimeStr);
                $checkoutDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $checkoutDate->format('Y-m-d') . ' ' . $checkoutTimeStr);

                $order->items()->create([
                    'product_id'    => $this->product->id,
                    'name'          => $this->product->name,
                    'price'         => max(0, $orderTotal - $extraFee),
                    'quantity'      => 1,
                    'extra_fee'     => 0,
                    'checkin_date'  => $checkinDateTime,
                    'checkout_date' => $checkoutDateTime,
                    'guest_count'   => $this->guests,
                ]);
            } else {
                foreach ($this->selectedSlots as $slot) {
                    $checkinDateTime  = Carbon::parse("{$slot['date']} {$slot['startTime']}");
                    $checkoutDateTime = Carbon::parse("{$slot['date']} {$slot['endTime']}");
                    if (isset($slot['overNight']) && $slot['overNight'] == 1) {
                        $checkoutDateTime->addDay();
                    }
                    $order->items()->create([
                        'product_id'    => $this->product->id,
                        'name'          => $this->product->name . ' - ' . $slot['timeslotLabel'],
                        'price'         => $slot['price'],
                        'quantity'      => 1,
                        'extra_fee'     => 0,
                        'checkin_date'  => $checkinDateTime,
                        'checkout_date' => $checkoutDateTime,
                        'guest_count'   => $this->guests,
                    ]);
                }
            }

            // ✅ 2. Phụ phí khách
            if ($extraFee > 0) {
                $order->items()->create([
                    'product_id'    => null,
                    'name'          => 'Phụ phí khách thêm (' . ($this->guests - 2) . ' người)',
                    'price'         => $extraFee,
                    'quantity'      => 1,
                    'extra_fee'     => $extraFee,
                    'checkin_date'  => $this->startTime,
                    'checkout_date' => $this->endTime,
                    'guest_count'   => $this->guests,
                ]);
            }

            // ✅ 3. Dịch vụ thêm
            if (!empty($this->selectedServices) && $this->additionalServices) {
                foreach ($this->selectedServices as $serviceId => $qty) {
                    if ($qty > 0) {
                        $service = $this->additionalServices->firstWhere('id', $serviceId);
                        if ($service) {
                            $order->services()->create([
                                'service_id'   => $service->id,
                                'service_name' => $service->name,
                                'price'        => $service->price,
                                'quantity'     => $qty,
                                'subtotal'     => $service->price * $qty,
                            ]);
                        }
                    }
                }
            }

            if ($this->appliedCoupon) {
                $this->appliedCoupon->incrementUsage();
            }

            return $order;
        });

        Session::forget('booking_data');
        Session::forget('booking_detail_state');
        $this->processPayOSPayment($order);

    } catch (\RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'SLOT_CONFLICT:')) {
            $conflict = json_decode(substr($e->getMessage(), 14), true);
            $statusLabel = match($conflict['order_status'] ?? '') {
                'paid'      => 'đã thanh toán',
                'confirmed' => 'đã xác nhận',
                'shipped'   => 'đang xử lý',
                default     => 'đang chờ thanh toán',
            };
            $this->dispatch('close-booking-modal');
            $this->dispatch('notify', [
                'message' => "Rất tiếc! Khung giờ {$conflict['slot_label']} ngày {$conflict['date']} đã được đặt bởi khách hàng {$conflict['buyer_name']} ({$conflict['masked_phone']}) - {$statusLabel}. Vui lòng chọn khung giờ khác.",
                'type'    => 'error',
            ]);
            return;
        }
        if (str_starts_with($e->getMessage(), 'DATE_CONFLICT:')) {
            $conflict = json_decode(substr($e->getMessage(), 14), true);
            $statusLabel = match($conflict['order_status'] ?? '') {
                'paid'      => 'đã thanh toán',
                'deposit'   => 'đặt cọc',
                'completed' => 'đã hoàn thành',
                'confirmed' => 'đã xác nhận',
                default     => 'đang chờ thanh toán',
            };
            $this->dispatch('close-booking-modal');
            $this->dispatch('notify', [
                'message' => "Rất tiếc! Ngày {$conflict['checkin']} – {$conflict['checkout']} đã được đặt bởi {$conflict['buyer_name']} ({$conflict['masked_phone']}) - {$statusLabel}. Vui lòng chọn ngày khác.",
                'type'    => 'error',
            ]);
            return;
        }
        Log::error('confirmBooking RuntimeException', ['error' => $e->getMessage()]);
        $this->dispatch('notify', ['message' => 'Có lỗi xảy ra, vui lòng thử lại.', 'type' => 'error']);
    } catch (\Exception $e) {
        Log::error('confirmBooking Exception', ['error' => $e->getMessage()]);
        $this->dispatch('notify', ['message' => 'Có lỗi xảy ra, vui lòng thử lại.', 'type' => 'error']);
    }
}

    /**
     * Kiểm tra xung đột đặt trùng ngày (style 2 - daterange).
     * Overlap: newStart < existingEnd AND newEnd > existingStart (strict cả 2 chiều).
     * Cho phép checkout cùng ngày checkin của đơn khác (không block ngày turnover).
     */
    protected function checkDateRangeConflicts(bool $lockForUpdate = false): ?array
    {
        if (empty($this->startTime) || empty($this->endTime) || !$this->product) {
            return null;
        }

        $newStart = Carbon::parse($this->startTime)->startOfDay();
        $newEnd   = Carbon::parse($this->endTime)->startOfDay();

        if ($newStart->gte($newEnd)) {
            return null;
        }

        $query = OrderItem::with('order')
            ->where('product_id', $this->product->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $newEnd)
            ->where('checkout_date', '>', $newStart)
            ->whereHas('order', function ($q) {
                $q->where(function ($inner) {
                    $inner->whereIn('status', ['paid', 'deposit', 'shipping', 'completed', 'confirmed'])
                          ->orWhere(function ($pendingQ) {
                              $pendingQ->where('status', 'pending')
                                       ->where(function ($expQ) {
                                           $expQ->whereNull('expired_at')
                                                ->orWhere('expired_at', '>', now());
                                       });
                          });
                });
            })
            ->orderBy('created_at', 'asc');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $conflict = $query->first();

        if ($conflict && $conflict->order) {
            $phone       = $conflict->order->buyer_phone ?? '';
            $maskedPhone = strlen($phone) >= 6
                ? substr($phone, 0, 3) . '****' . substr($phone, -2)
                : '***';

            return [
                'checkin'      => Carbon::parse($conflict->checkin_date)->format('d/m/Y'),
                'checkout'     => Carbon::parse($conflict->checkout_date)->format('d/m/Y'),
                'buyer_name'   => $conflict->order->buyer_name ?? 'Khách hàng',
                'masked_phone' => $maskedPhone,
                'order_status' => $conflict->order->status,
            ];
        }

        return null;
    }

    /**
     * Kiểm tra xung đột đặt trùng khung giờ.
     * So sánh chính xác theo checkin_date = date + start_time của timeslot.
     * Trả về mảng thông tin khung giờ đầu tiên bị trùng, hoặc null nếu không có.
     */
    protected function checkSlotConflicts(bool $lockForUpdate = false): ?array
    {
        if (empty($this->selectedSlots) || !$this->product) {
            return null;
        }

        // Build map timeslot_id => start_time từ $this->timeSlots đã load sẵn
        $timeSlotsMap = collect($this->timeSlots)->keyBy('timeslot_id');

        foreach ($this->selectedSlots as $slot) {
            $timeslotId = $slot['timeslotId'];
            $slotDate   = $slot['date']; // Y-m-d

            // Lấy start_time chính xác từ time_slots
            $tsInfo    = $timeSlotsMap->get($timeslotId);
            $startTime = $tsInfo['start_time'] ?? null;

            if (!$startTime) {
                // Fallback: query DB
                $ts        = TimeSlot::find($timeslotId);
                $startTime = $ts?->start_time ?? $slot['startTime'];
            }

            // Chuẩn hoá H:i:s
            if (strlen($startTime) === 5) {
                $startTime .= ':00';
            }

            // checkin_date lưu dưới dạng 'Y-m-d H:i:s' = date + start_time
            $exactCheckin = Carbon::createFromFormat('Y-m-d H:i:s', "{$slotDate} {$startTime}");

            $query = OrderItem::with('order')
                ->where('product_id', $this->product->id)
                ->where('checkin_date', $exactCheckin)
                ->whereHas('order', function ($q) {
                    $q->where(function ($inner) {
                        // Đơn đã thanh toán / xác nhận → luôn block
                        $inner->whereIn('status', ['paid', 'shipped', 'confirmed'])
                              // Đơn pending → chỉ block nếu chưa hết hạn 15p
                              ->orWhere(function ($pendingQ) {
                                  $pendingQ->where('status', 'pending')
                                           ->where(function ($expQ) {
                                               $expQ->whereNull('expired_at')
                                                    ->orWhere('expired_at', '>', now());
                                           });
                              });
                    });
                })
                ->orderBy('created_at', 'asc');

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            $conflict = $query->first();

            if ($conflict && $conflict->order) {
                $phone = $conflict->order->buyer_phone ?? '';
                $maskedPhone = strlen($phone) >= 6
                    ? substr($phone, 0, 3) . '****' . substr($phone, -2)
                    : '***';

                return [
                    'slot_label'   => $slot['timeslotLabel'] ?? "{$slot['startTime']} - {$slot['endTime']}",
                    'date'         => $slot['date'],
                    'buyer_name'   => $conflict->order->buyer_name ?? 'Khách hàng',
                    'masked_phone' => $maskedPhone,
                    'order_status' => $conflict->order->status,
                    'booked_at'    => $conflict->order->created_at,
                ];
            }
        }

        return null;
    }

    protected function getBranchCategoryId()
    {
        if (!$this->product->relationLoaded('categories')) {
            $this->product->load('categories');
        }

        $branchCategory = $this->product->categories()
            ->where('category_type', 'product')
            ->first();

        if ($branchCategory) {
            return $branchCategory->id;
        }

        return null;
    }

    protected function processPayOSPayment($order)
    {
        try {
            $roomDetails = [
                'productName' => $this->product->name,
                'guests' => $this->guests,
            ];

            $paymentData = $this->paymentService->createRoomBookingPaymentData($order, $roomDetails);
            $paymentLink = $this->paymentService->createPaymentLink($paymentData);

            $order->update([
                'checkout_url' => $paymentLink['checkoutUrl'] ?? $paymentLink['data']['checkoutUrl'] ?? null,
                'status'       => 'pending',
                'expired_at'   => now()->addMinutes(15),
            ]);

            return redirect()->to($paymentLink['checkoutUrl'] ?? $paymentLink['data']['checkoutUrl']);
        } catch (\Throwable $th) {
            $order->update(['status' => 'failed']);
            Log::error('processPayOSPayment error', ['error' => $th->getMessage()]);
            $this->dispatch('notify', [
                'message' => 'Lỗi thanh toán, vui lòng thử lại.',
                'type' => 'error'
            ]);
        }
    }

    public function fetchProduct()
    {
        $now = Carbon::now();
        $endDate = Carbon::now()->addMonths(12)->endOfDay();

        $query = Product::with(
            [
                'categories',
                'media',
                'orderItems' => function ($query) use ($now, $endDate) {
                    $query->where('checkout_date', '>', $now)
                        ->where('checkin_date', '<=', $endDate)
                        ->whereHas('order', function ($subQuery) {
                            $subQuery->where(function ($inner) {
                                $inner->whereIn('status', ['paid', 'shipped'])
                                      ->orWhere(function ($pendingQ) {
                                          $pendingQ->where('status', 'pending')
                                                   ->where(function ($expQ) {
                                                       $expQ->whereNull('expired_at')
                                                            ->orWhere('expired_at', '>', now());
                                                   });
                                      });
                            });
                        });
                },
                'orderItems.order'
            ]
        )
            ->where([
                'is_activated' => true,
                'type' => 'simple'
            ])->whereHas('categories', function ($query) {
                $query->where('status', 1);
            });

        if (isset($this->config['category'])) {
            $categoryIds = json_decode($this->config['category'], true);
            if (is_array($categoryIds) && !empty($categoryIds)) {
                $query->whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        try {
            $product = $query->where('slug', $this->slug)->firstOrFail();

            $product->load([
                'roomTimeSlots' => function ($query) {
                    $query->join('time_slots', 'room_time_slots.timeslot_id', '=', 'time_slots.id')
                        ->select('room_time_slots.*')
                        ->orderBy('time_slots.start_time', 'asc');
                },
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => function ($query) {
                    $query->where('is_active', true);
                }
            ]);

            $categories = [
                'c1' => null,
                'c2' => null,
                'c3' => null
            ];

            if ($product->categories->isNotEmpty()) {
                $category = $product->categories->first();
                $categories['c3'] = $category->name;
                if ($category->parent) {
                    $categories['c2'] = $category->parent->name;
                    if ($category->parent->parent) {
                        $categories['c1'] = $category->parent->parent->name;
                    }
                }
            }

            $timeSlots = [];
            foreach ($product->roomTimeSlots as $roomTimeSlot) {
                $timeSlot = $roomTimeSlot->timeSlot;

                $timeSlots[] = [
                    'timeslot_id' => $timeSlot->id,
                    'start_time' => $timeSlot->start_time,
                    'end_time' => $timeSlot->end_time,
                    'timeslot_label' => $timeSlot->label,
                    'timeslot_price' => $roomTimeSlot->price,
                    'over_night' => $roomTimeSlot->over_night ?? 0,
                    'promotions' => $roomTimeSlot->promotions,
                ];
            }

            $product->time_slots = $timeSlots;
            $product->category_names = $categories;
            return $product;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }
    }

    public function fetchDateStatusOrder($productId)
    {
        $orders = Order::with(['items' => function ($query) use ($productId) {
            $query->where('product_id', $productId)
                ->whereNotNull('checkin_date')
                ->whereNotNull('checkout_date');
        }])
            ->whereHas('items', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->get();
        $bookedDates = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $bookedDates[] = [
                    'checkin_date' => $item->checkin_date ? $item->checkin_date->format('Y-m-d H:i') : null,
                    'checkout_date' => $item->checkout_date ? $item->checkout_date->format('Y-m-d H:i') : null,
                ];
            }
        }

        $this->bookedDates = $bookedDates;
    }

    public function fetchProductTags($productId)
    {
        $productModelClass = Product::class;
        $tags = DB::table('products')
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(cms_tags.name, "$.vi")) as tag_name'),
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(cms_tags.image, "$.vi")) as tag_image'),
                DB::raw('cms_tags.type as tag_type')
            )
            ->where('products.id', $productId)
            ->join('taggables as tb', function ($join) use ($productModelClass) {
                $join->on('tb.taggable_id', '=', 'products.id')
                    ->where('tb.taggable_type', '=', $productModelClass);
            })
            ->join('tags', 'tags.id', '=', 'tb.tag_id')
            ->where(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(cms_tags.name, "$.vi"))'), 'IS NOT', null)
            ->where(DB::raw('JSON_UNQUOTE(JSON_EXTRACT(cms_tags.name, "$.vi"))'), '!=', 'null')
            ->get();

        return $tags;
    }

    public function fetchRoomTimeSlots($productId)
    {
        $items = DB::table('products as p')
            ->select(
                'tl.start_time',
                'tl.end_time',
                'tl.label',
                'rts.price'
            )
            ->join('room_time_slots as rts', 'rts.room_id', '=', 'p.id')
            ->join('time_slots as tl', 'tl.id', '=', 'rts.timeslot_id')
            ->where('p.id', $productId)
            ->where('p.is_activated', 1)
            ->orderBy('tl.start_time')
            ->get();

        $roomTimeSlots = [];
        foreach ($items as $item) {
            $timeRange = $item->label ?: date('H:i', strtotime($item->start_time)) . ' - ' . date('H:i', strtotime($item->end_time));
            $roomTimeSlots[] = [
                'price' => $item->price,
                'time_range' => $timeRange
            ];
        }

        return $roomTimeSlots;
    }

    /**
     * Kiểm tra xem ngày cụ thể có phải full booking không
     */
    protected function hasFullDayBookingForDate($dateString)
    {
        if (empty($this->selectedSlots) || !$this->product) {
            return false;
        }

        $totalTimeslots = count($this->timeSlots);
        if ($totalTimeslots === 0) {
            return false;
        }

        $slotsForDate = collect($this->selectedSlots)->where('date', $dateString)->count();
        return $slotsForDate === $totalTimeslots;
    }

    /**
     * ⭐ METHOD MỚI: Kiểm tra promotion có áp dụng cho slot không
     * Copy từ Book.php để đảm bảo đồng bộ 100%
     */
    protected function isPromotionApplicable($promotion, Carbon $slotDateTime, Carbon $currentDate, $slot = null)
    {
        // --- BƯỚC 1: LẤY KHOẢNG THỜI GIAN TUYỆT ĐỐI CỦA PROMOTION ---
        $promotionStart = Carbon::parse($promotion->start_at);
        $promotionEnd = Carbon::parse($promotion->end_at);

        // --- BƯỚC 2: XÁC ĐỊNH KHOẢNG THỜI GIAN CỦA SLOT ---
        // Mặc định slot kéo dài 1 tiếng nếu không tìm thấy cấu hình end_time
        $slotStart = $slotDateTime->copy();
        $slotEnd = $slotStart->copy()->addHour();

        if ($slot && isset($slot->timeSlot)) {
            $endTimeStr = $slot->timeSlot->end_time; // Lấy "12:20:00" từ DB
            $slotEnd = Carbon::createFromFormat('Y-m-d H:i:s', $currentDate->format('Y-m-d') . ' ' . $endTimeStr);

            // Xử lý nếu slot kết thúc qua ngày hôm sau (ví dụ 23:00 -> 01:00)
            if ($slotEnd->lt($slotStart)) {
                $slotEnd->addDay();
            }
        }

        /**
         * LOGIC GIAO THOA (OVERLAP):
         * Slot được áp dụng nếu: (Bắt đầu Slot < Kết thúc Promo) VÀ (Kết thúc Slot > Bắt đầu Promo)
         * Với Slot 09:30-12:20 và Promo bắt đầu lúc 12:00:
         * (09:30 < PromoEnd) AND (12:20 > 12:00) => THỎA MÃN
         */
        $isOverlapping = $slotStart->lt($promotionEnd) && $slotEnd->gt($promotionStart);

        if (!$isOverlapping) {
            return false;
        }

        // --- BƯỚC 3: KIỂM TRA NGÀY BỊ LOẠI TRỪ (EXCLUDED DATES) ---
        $excludedDates = $promotion->excluded_dates ?? [];
        if (is_string($excludedDates)) {
            $excludedDates = json_decode($excludedDates, true);
        }
        $isExcluded = collect($excludedDates)->contains(function ($exDate) use ($currentDate) {
            $exDateVal = isset($exDate['date']) ? $exDate['date'] : $exDate;
            return Carbon::parse($exDateVal)->isSameDay($currentDate);
        });
        if ($isExcluded) return false;

        // --- BƯỚC 4: KIỂM TRA KHUNG GIỜ CỐ ĐỊNH (APPLICABLE TIME SLOTS) ---
        // Nếu promo yêu cầu chỉ áp dụng vào giờ vàng lặp lại mỗi ngày
        if (isset($promotion->applicable_time_slots) && !empty($promotion->applicable_time_slots)) {
            $applicableTimeSlots = is_string($promotion->applicable_time_slots)
                ? json_decode($promotion->applicable_time_slots, true)
                : $promotion->applicable_time_slots;

            if (!empty($applicableTimeSlots)) {
                $slotStartTimeStr = $slotStart->format('H:i');
                $slotEndTimeStr = $slotEnd->format('H:i');

                $isTimeApplicable = collect($applicableTimeSlots)->contains(function ($timeRange) use ($slotStartTimeStr, $slotEndTimeStr) {
                    if (isset($timeRange['start']) && isset($timeRange['end'])) {
                        $pStart = substr($timeRange['start'], 0, 5);
                        $pEnd = substr($timeRange['end'], 0, 5);

                        // Slot giao thoa với khung giờ cấu hình lặp lại
                        return $slotStartTimeStr < $pEnd && $slotEndTimeStr > $pStart;
                    }
                    return false;
                });

                if (!$isTimeApplicable) return false;
            }
        }

        // --- BƯỚC 5: KIỂM TRA THỨ TRONG TUẦN (DAYS OF WEEK) ---
        if (isset($promotion->applicable_days_of_week) && !empty($promotion->applicable_days_of_week)) {
            $applicableDays = is_string($promotion->applicable_days_of_week)
                ? json_decode($promotion->applicable_days_of_week, true)
                : $promotion->applicable_days_of_week;

            if (!in_array($slotStart->dayOfWeek, $applicableDays)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ⭐ METHOD CẬP NHẬT: Tính giá cho 1 slot với logic đầy đủ từ Book.php
     */
    public function calculateSlotPrice($slot, $dateString, $slotStartTime = null)
    {
        $originalPrice = (float) $slot->price;
        $priceAfterIncrease = $originalPrice;
        $finalPrice = $originalPrice;
        $totalDiscount = 0;
        $activePromotions = [];

        // ✅ Kiểm tra xem ngày này có phải full booking không
        $isFullDayBooking = $this->hasFullDayBookingForDate($dateString);

        // ✅ Tạo slotDateTime để kiểm tra chính xác
        if ($slotStartTime) {
            $timeString = strlen($slotStartTime) === 5 ? $slotStartTime . ':00' : $slotStartTime;
            $slotDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $dateString . ' ' . $timeString);
        } else {
            $slotDateTime = Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay();
        }

        $currentDate = Carbon::createFromFormat('Y-m-d', $dateString)->startOfDay();

        if ($slot->promotions->isEmpty()) {
            return [
                'final_price' => $finalPrice,
                'original_price' => $originalPrice,
                'price_after_increase' => $originalPrice,
                'increase_amount' => 0,
                'total_discount' => 0,
                'has_promotion' => false,
                'is_increase' => false,
                'promotions' => [],
                'is_full_day_booking' => $isFullDayBooking
            ];
        }

        if (is_object($slot) && !($slot instanceof RoomTimeSlot)) {
            // Tìm RoomTimeSlot thật từ database
            $realSlot = RoomTimeSlot::with(['timeSlot', 'promotions'])
                ->where('room_id', $this->product->id)
                ->whereHas('timeSlot', function($q) use ($slotStartTime) {
                    if ($slotStartTime) {
                        $q->where('start_time', $slotStartTime);
                    }
                })
                ->first();

            if ($realSlot) {
                $slot = $realSlot;
            }
        }

        // ========================================
        // ✅ NẾU LÀ FULL BOOKING - CHỈ ÁP DỤNG TĂNG GIÁ
        // ========================================
        if ($isFullDayBooking) {
            foreach ($slot->promotions as $promotion) {
                // ⭐ Gọi isPromotionApplicable để kiểm tra đầy đủ
                if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                    continue;
                }

                $value = (float) $promotion->value;

                // ✅ CHỈ ÁP DỤNG TĂNG GIÁ
                if (in_array($promotion->type, ['increase_fixed', 'increase_percentage'])) {
                    switch ($promotion->type) {
                        case 'increase_fixed':
                            $priceAfterIncrease += $value;
                            $finalPrice += $value;
                            break;
                        case 'increase_percentage':
                            $increaseAmount = ($priceAfterIncrease * ($value / 100));
                            $priceAfterIncrease += $increaseAmount;
                            $finalPrice += $increaseAmount;
                            break;
                    }
                    $activePromotions[] = $promotion;
                }
            }

            return [
                'final_price' => max(0, $finalPrice),
                'original_price' => $originalPrice,
                'price_after_increase' => $priceAfterIncrease,
                'increase_amount' => $priceAfterIncrease - $originalPrice,
                'total_discount' => 0, // ❌ Không có discount khi full booking
                'has_promotion' => count($activePromotions) > 0,
                'is_increase' => $priceAfterIncrease > $originalPrice,
                'promotions' => $activePromotions,
                'is_full_day_booking' => true
            ];
        }

        // ========================================
        // ✅ LOGIC CHO TRƯỜNG HỢP KHÔNG PHẢI FULL BOOKING
        // ========================================

        // Bước 1: Áp dụng các promotion TĂNG GIÁ trước
        foreach ($slot->promotions as $promotion) {
            // ⭐ Gọi isPromotionApplicable thay vì kiểm tra đơn giản
            if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                continue;
            }

            $value = (float) $promotion->value;

            if (in_array($promotion->type, ['increase_fixed', 'increase_percentage'])) {
                switch ($promotion->type) {
                    case 'increase_fixed':
                        $priceAfterIncrease += $value;
                        $finalPrice += $value;
                        break;
                    case 'increase_percentage':
                        $increaseAmount = ($priceAfterIncrease * ($value / 100));
                        $priceAfterIncrease += $increaseAmount;
                        $finalPrice += $increaseAmount;
                        break;
                }
                $activePromotions[] = $promotion;
            }
        }

        // Bước 2: Áp dụng các promotion GIẢM GIÁ sau
        foreach ($slot->promotions as $promotion) {
            // ⭐ Gọi isPromotionApplicable thay vì kiểm tra đơn giản
            if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                continue;
            }

            $value = (float) $promotion->value;

            if (in_array($promotion->type, ['fixed', 'percentage'])) {
                switch ($promotion->type) {
                    case 'fixed':
                        $finalPrice -= $value;
                        $totalDiscount += $value;
                        break;
                    case 'percentage':
                        $discount = ($finalPrice * ($value / 100));
                        $finalPrice -= $discount;
                        $totalDiscount += $discount;
                        break;
                }

                $exists = collect($activePromotions)->contains(fn($p) => $p->id === $promotion->id);
                if (!$exists) {
                    $activePromotions[] = $promotion;
                }
            }
        }

        $finalPrice = max(0, $finalPrice);

        return [
            'final_price' => $finalPrice,
            'original_price' => $originalPrice,
            'price_after_increase' => $priceAfterIncrease,
            'increase_amount' => $priceAfterIncrease - $originalPrice,
            'total_discount' => $totalDiscount,
            'has_promotion' => count($activePromotions) > 0,
            'is_increase' => $priceAfterIncrease > $originalPrice,
            'promotions' => $activePromotions,
            'is_full_day_booking' => false
        ];
    }

    public function render()
    {
        if (is_null($this->additionalServices)) {
            $this->additionalServices = $this->product
                ? $this->product->additionalServices()->where('additional_services.is_active', 1)->get()
                : collect();
        }
        if ($this->product) {
            $now = Carbon::now();
            $endDate = Carbon::now()->addMonths(12)->endOfDay();

            $this->product->load([
                'orderItems' => function ($query) use ($now, $endDate) {
                    $query->where('checkout_date', '>', $now)
                        ->where('checkin_date', '<=', $endDate)
                        ->whereHas('order', function ($subQuery) {
                            $subQuery->where(function ($inner) {
                                $inner->whereIn('status', ['paid', 'shipped'])
                                      ->orWhere(function ($pendingQ) {
                                          $pendingQ->where('status', 'pending')
                                                   ->where(function ($expQ) {
                                                       $expQ->whereNull('expired_at')
                                                            ->orWhere('expired_at', '>', now());
                                                   });
                                      });
                            });
                        });
                },
                'orderItems.order'
            ]);
        }

        $now = Carbon::now();

        return view('bladethemev1::livewire.product-detail', [
            'product' => $this->product,
            'productTags' => $this->productTags,
            'roomTimeSlots' => $this->roomTimeSlots,
            'mediaSecond' => $this->mediaSecond,
            'shortDescription' => $this->shortDescription,
            'categories' => $this->categories,
            'timeSlots' => $this->timeSlots,
            'dates' => $this->dates,
            'today_date' => $now->format('Y-m-d'),
            'current_time' => $now->format('H:i:s'),
             'additionalServices' => $this->additionalServices ?? collect(),
            'selectedServices'   => $this->selectedServices ?? [],
        ]);
    }
}