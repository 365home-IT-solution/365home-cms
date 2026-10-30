<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\PromotionCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class DailyRoomController extends Controller
{
    public function __construct(private PromotionCalculator $calculator) {}

    /**
     * GET /api/rooms/{id}/dates
     *
     * Chế độ calendar (tháng):
     *   ?month=YYYY-MM  → toàn bộ tháng, không có totals
     *
     * Chế độ booking preview (khoảng ngày):
     *   ?from=YYYY-MM-DD&to=YYYY-MM-DD
     *   from = checkin, to = checkout (exclusive — không tính ngày checkout)
     *   Trả về per-night breakdown + subtotal + deposit
     */
    public function dates(Request $request, string $id): JsonResponse
    {
        $room = $this->loadRoom($id);
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $isRangeMode = $request->has('from') || $request->has('to');

        if ($isRangeMode) {
            $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to'   => 'required|date_format:Y-m-d|after:from',
            ]);
            $rangeStart = Carbon::parse($request->query('from'))->startOfDay();
            $rangeEnd   = Carbon::parse($request->query('to'))->startOfDay();

            if ($rangeStart->diffInDays($rangeEnd) > 92) {
                return response()->json(['message' => 'Khoảng ngày tối đa 3 tháng.'], 422);
            }
        } else {
            $monthStr = $request->query('month', now()->format('Y-m'));
            try {
                $month = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
            } catch (\Throwable) {
                return response()->json(['message' => 'Định dạng month không hợp lệ. Dùng YYYY-MM.'], 422);
            }
            $rangeStart = $month->copy()->startOfMonth();
            $rangeEnd   = $month->copy()->endOfMonth();
        }

        $slotsByDate = $this->slotsByDate($room);
        $nightsLeft  = $this->nightsAvailability($room, $rangeStart, $rangeEnd->copy()->addDay(), $slotsByDate);
        $basePrice   = (float) $room->price;
        $defCheckin  = $room->default_checkin  ?? '14:00';
        $defCheckout = $room->default_checkout ?? '12:00';

        $dates              = [];
        $subtotal           = 0;
        $totalPromoDiscount = 0;
        $current            = $rangeStart->copy();

        // Range mode: to = checkout (exclusive). Month mode: inclusive (lte).
        while ($isRangeMode ? $current->lt($rangeEnd) : $current->lte($rangeEnd)) {
            $dateStr = $current->format('Y-m-d');
            $rts     = $slotsByDate->get($dateStr);

            $price  = $rts?->price !== null
                ? (float) $rts->price
                : app(\App\Services\PriceBoards\StayPriceResolver::class)->nightPrice($room, $dateStr, $basePrice);
            $promos = $this->calculator->calculateForDate($rts, $price, $dateStr);
            $disc   = $price - $promos['final_price'];

            $subtotal           += $price;
            $totalPromoDiscount += $disc;

            $dates[] = [
                'date'               => $dateStr,
                'price'              => (int) $price,
                'final_price'        => (int) $promos['final_price'],
                'promotion_discount' => (int) $disc,
                'has_override'       => $rts !== null,
                'available'          => $nightsLeft[$dateStr]['remaining'] > 0,
                // Số phòng còn đặt được đêm này (phòng đơn: 1/0; biến thể phòng: 0..variant_quantity).
                'remaining'          => $nightsLeft[$dateStr]['remaining'],
                // Admin khoá trọn đêm này (hiện 🔒) — khác "hết phòng" do khách đã đặt.
                'is_blocked'         => $nightsLeft[$dateStr]['blocked'],
                'checkin'            => $rts?->checkin  ?? $defCheckin,
                'checkout'           => $rts?->checkout ?? $defCheckout,
                'promotions'         => $promos['applied'],
            ];

            $current->addDay();
        }

        $response = [
            'from'             => $rangeStart->format('Y-m-d'),
            'to'               => $rangeEnd->format('Y-m-d'),
            'base_price'       => (int) $basePrice,
            'default_checkin'  => $defCheckin,
            'default_checkout' => $defCheckout,
            // Biến thể phòng: 1 phòng đại diện variant_quantity phòng giống nhau — app hiện "còn X phòng" + cho chọn số lượng.
            'is_variant'       => (bool) $room->is_variant,
            'variant_quantity' => $room->roomCapacity(),
            'dates'            => $dates,
        ];

        // Range mode: thêm tổng giá + deposit + holding
        if ($isRangeMode) {
            // Đánh dấu ngày đang bị giữ bởi session khác
            $mySession   = $request->query('session_id');
            $activeHolds = array_values(array_filter(
                Cache::get("daily_holds:{$room->id}", []),
                function ($h) use ($mySession) {
                    if ($mySession && ($h['session_id'] ?? '') === $mySession) return false;
                    return Carbon::parse($h['expires_at'] ?? now()->subSecond())->isFuture();
                }
            ));

            $dates = array_map(function ($day) use ($activeHolds) {
                $d = $day['date'];
                $day['holding'] = \count(array_filter(
                    $activeHolds,
                    fn ($h) => $d >= $h['checkin'] && $d < $h['checkout']
                )) > 0;
                return $day;
            }, $dates);

            $response['dates'] = $dates;

            $nights          = \count($dates);
            $totalAfterPromo = (int) max(0, $subtotal - $totalPromoDiscount);
            $depositMin      = (int) ($room->deposit_min_nights  ?? 0);
            $depositPct      = (int) ($room->deposit_multi_night ?? 50);
            $canDeposit      = $depositMin > 0 && $nights >= $depositMin && $depositPct < 100;
            $depositAmt      = $canDeposit ? (int) ceil($totalAfterPromo * $depositPct / 100) : null;

            $response['nights']             = $nights;
            $response['subtotal']           = (int) $subtotal;
            $response['promotion_discount'] = (int) $totalPromoDiscount;
            $response['total_after_promo']  = $totalAfterPromo;
            $response['deposit']            = $canDeposit ? [
                'eligible'         => true,
                'min_nights'       => $depositMin,
                'percentage'       => $depositPct,
                'deposit_amount'   => $depositAmt,
                'remaining_amount' => $totalAfterPromo - $depositAmt,
            ] : [
                'eligible'   => false,
                'min_nights' => $depositMin,
            ];
        }

        return response()->json($response);
    }

    /**
     * GET /api/rooms/{id}/price-preview?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD
     * Tính giá tạm tính — gộp đủ: per-night breakdown, availability, deposit.
     */
    public function pricePreview(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'checkin'       => 'required|date_format:Y-m-d',
            'checkout'      => 'required|date_format:Y-m-d|after:checkin',
            'room_quantity' => 'sometimes|integer|min:1|max:500',
        ]);

        $room = $this->loadRoom($id);
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $checkin  = Carbon::parse($request->checkin);
        $checkout = Carbon::parse($request->checkout);
        $nights   = (int) $checkin->diffInDays($checkout);
        // Biến thể phòng: tiền nhân theo số phòng khách chọn — cùng cách BookingController tính lúc tạo đơn.
        $qty      = app(\App\Services\RoomInventory::class)->requestedQuantity($room, $request->input('room_quantity'));

        $slotsByDate = $this->slotsByDate($room);
        $nightsLeft  = $this->nightsAvailability($room, $checkin, $checkout, $slotsByDate);
        $basePrice   = (float) $room->price;
        $defCheckin  = $room->default_checkin  ?? '14:00';
        $defCheckout = $room->default_checkout ?? '12:00';

        $breakdown          = [];
        $subtotal           = 0;
        $totalPromoDiscount = 0;

        $current = $checkin->copy();
        while ($current->lt($checkout)) {
            $dateStr = $current->format('Y-m-d');
            $rts     = $slotsByDate->get($dateStr);

            $price  = $rts?->price !== null
                ? (float) $rts->price
                : app(\App\Services\PriceBoards\StayPriceResolver::class)->nightPrice($room, $dateStr, $basePrice);
            $promos = $this->calculator->calculateForDate($rts, $price, $dateStr);
            $disc   = $price - $promos['final_price'];

            $subtotal           += $price * $qty;
            $totalPromoDiscount += $disc * $qty;

            $breakdown[] = [
                'date'               => $dateStr,
                'price'              => (int) $price,
                'final_price'        => (int) $promos['final_price'],
                'promotion_discount' => (int) $disc,
                'available'          => $nightsLeft[$dateStr]['remaining'] >= $qty,
                'remaining'          => $nightsLeft[$dateStr]['remaining'],
                'is_blocked'         => $nightsLeft[$dateStr]['blocked'],
                'checkin'            => $rts?->checkin  ?? $defCheckin,
                'checkout'           => $rts?->checkout ?? $defCheckout,
                'promotions'         => $promos['applied'],
            ];

            $current->addDay();
        }

        $firstRts        = $slotsByDate->get($checkin->format('Y-m-d'));
        $lastRts         = $slotsByDate->get($checkout->copy()->subDay()->format('Y-m-d'));
        $totalAfterPromo = (int) max(0, $subtotal - $totalPromoDiscount);

        $depositMin = (int) ($room->deposit_min_nights  ?? 0);
        $depositPct = (int) ($room->deposit_multi_night ?? 50);
        $canDeposit = $depositMin > 0 && $nights >= $depositMin && $depositPct < 100;
        $depositAmt = $canDeposit ? (int) ceil($totalAfterPromo * $depositPct / 100) : null;

        return response()->json([
            'nights'             => $nights,
            // Biến thể phòng: số phòng đang tính tiền + số phòng còn trống cho CẢ khoảng ngày (đêm ít phòng nhất).
            'room_quantity'      => $qty,
            'remaining_rooms'    => $nights > 0 ? min(array_column($nightsLeft, 'remaining')) : 0,
            'is_variant'         => (bool) $room->is_variant,
            'variant_quantity'   => $room->roomCapacity(),
            'checkin_date'       => $checkin->format('Y-m-d'),
            'checkout_date'      => $checkout->format('Y-m-d'),
            'checkin_time'       => $firstRts?->checkin  ?? $defCheckin,
            'checkout_time'      => $lastRts?->checkout  ?? $defCheckout,
            'base_price'         => (int) $basePrice,
            'default_checkin'    => $defCheckin,
            'default_checkout'   => $defCheckout,
            'nights_breakdown'   => $breakdown,
            'subtotal'           => (int) $subtotal,
            'promotion_discount' => (int) $totalPromoDiscount,
            'total_after_promo'  => $totalAfterPromo,
            'deposit' => $canDeposit ? [
                'eligible'         => true,
                'min_nights'       => $depositMin,
                'percentage'       => $depositPct,
                'deposit_amount'   => $depositAmt,
                'remaining_amount' => $totalAfterPromo - $depositAmt,
            ] : [
                'eligible'   => false,
                'min_nights' => $depositMin,
            ],
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function loadRoom(string $id): ?Product
    {
        return Product::where('id', $id)
            ->where('is_activated', true)
            ->where('styles', 2)
            ->with([
                'roomTimeSlots' => fn ($q) => $q->whereHas('timeSlot', fn ($q2) => $q2->where('type', 'date')),
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();
    }

    private function slotsByDate(Product $room)
    {
        return $room->roomTimeSlots->keyBy(fn ($rts) => $rts->timeSlot?->label);
    }

    /**
     * Mỗi đêm trong [$from, $to) → ['remaining' => số phòng còn đặt được, 'blocked' => admin khoá trọn đêm].
     * Phòng đơn: còn 1 nếu đêm chưa có đơn giữ phòng. Biến thể phòng: sức chứa trừ số phòng bị giữ cùng lúc cao nhất
     * trong đêm (đơn + phần phòng admin khoá bớt), theo giờ nhận/trả phòng thật của đêm đó (RoomInventory).
     * Đơn chờ thanh toán đã quá hạn không còn giữ phòng.
     *
     * @return array<string, array{remaining: int, blocked: bool}>
     */
    private function nightsAvailability(Product $room, Carbon $from, Carbon $to, $slotsByDate): array
    {
        $inventory = app(\App\Services\RoomInventory::class);
        $items     = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '>', $from)
            ->where('checkin_date', '<', $to->copy()->addDay())
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'confirmed'])->where(fn ($q) => $q
                ->where('status', '!=', 'pending')
                ->orWhereNull('expired_at')
                ->orWhere('expired_at', '>', now())))
            ->get(['checkin_date', 'checkout_date', 'quantity']);

        $blockedDates = [];
        foreach ($room->fullBlockedRanges() as $range) {
            for ($d = Carbon::parse($range['start']); $d->lte(Carbon::parse($range['end'])); $d->addDay()) {
                $blockedDates[$d->format('Y-m-d')] = true;
            }
        }

        $bookedNights = [];
        if (! $room->is_variant) {
            foreach ($items as $item) {
                for ($night = Carbon::parse($item->checkin_date)->startOfDay(); $night->lt(Carbon::parse($item->checkout_date)->startOfDay()); $night->addDay()) {
                    $bookedNights[$night->format('Y-m-d')] = true;
                }
            }
        } else {
            $items = $items->concat($inventory->blockItems($room));
        }

        $result = [];
        for ($night = $from->copy()->startOfDay(); $night->lt($to->copy()->startOfDay()); $night->addDay()) {
            $date    = $night->format('Y-m-d');
            $blocked = isset($blockedDates[$date]);

            if ($room->is_variant) {
                $rts    = $slotsByDate->get($date);
                $start  = Carbon::parse("{$date} " . ($rts?->checkin ?? $room->default_checkin ?? '14:00'));
                $end    = Carbon::parse($night->copy()->addDay()->format('Y-m-d') . ' ' . ($rts?->checkout ?? $room->default_checkout ?? '12:00'));
                $remain = max(0, $room->roomCapacity() - $inventory->peak($items, $start, $end));
            } else {
                $remain = isset($bookedNights[$date]) ? 0 : 1;
            }

            $result[$date] = ['remaining' => $blocked ? 0 : $remain, 'blocked' => $blocked];
        }

        return $result;
    }
}
