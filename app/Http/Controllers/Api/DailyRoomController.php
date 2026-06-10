<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\PromotionCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class DailyRoomController extends Controller
{
    public function __construct(private PromotionCalculator $calculator) {}

    /**
     * GET /api/rooms/{id}/dates
     *
     * Hai cách dùng:
     *   ?month=YYYY-MM               → toàn bộ tháng đó
     *   ?from=YYYY-MM-DD&to=YYYY-MM-DD → khoảng ngày tùy ý (cross-month)
     */
    public function dates(Request $request, string $id): JsonResponse
    {
        $room = $this->loadRoom($id);
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        // ── Xác định khoảng ngày cần lấy ─────────────────────────────────────
        if ($request->has('from') || $request->has('to')) {
            $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to'   => 'required|date_format:Y-m-d|after_or_equal:from',
            ]);
            $rangeStart = Carbon::parse($request->query('from'))->startOfDay();
            $rangeEnd   = Carbon::parse($request->query('to'))->startOfDay();

            // Giới hạn tối đa 3 tháng để tránh query quá nặng
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
        $bookedDates = $this->bookedDates($room->id, $rangeStart, $rangeEnd);
        $basePrice   = (float) $room->price;
        $defCheckin  = $room->default_checkin  ?? '14:00';
        $defCheckout = $room->default_checkout ?? '12:00';

        $dates   = [];
        $current = $rangeStart->copy();

        while ($current->lte($rangeEnd)) {
            $dateStr = $current->format('Y-m-d');
            $rts     = $slotsByDate->get($dateStr);

            $price  = $rts?->price !== null ? (float) $rts->price : $basePrice;
            $promos = $this->calculator->calculateForDate($rts, $price, $dateStr);

            $dates[] = [
                'date'         => $dateStr,
                'price'        => (int) $price,
                'final_price'  => $promos['final_price'] != $price ? (int) $promos['final_price'] : null,
                'has_override' => $rts !== null,
                'checkin'      => $rts?->checkin  ?? $defCheckin,
                'checkout'     => $rts?->checkout ?? $defCheckout,
                'available'    => ! in_array($dateStr, $bookedDates),
                'promotions'   => $promos['applied'],
            ];

            $current->addDay();
        }

        return response()->json([
            'from'             => $rangeStart->format('Y-m-d'),
            'to'               => $rangeEnd->format('Y-m-d'),
            'base_price'       => (int) $basePrice,
            'default_checkin'  => $defCheckin,
            'default_checkout' => $defCheckout,
            'dates'            => $dates,
        ]);
    }

    /**
     * GET /api/rooms/{id}/price-preview?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD
     * Xem trước giá cho khoảng ngày đặt.
     */
    public function pricePreview(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'checkin'  => 'required|date_format:Y-m-d',
            'checkout' => 'required|date_format:Y-m-d|after:checkin',
        ]);

        $room = $this->loadRoom($id);
        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại.'], 404);
        }

        $checkin  = Carbon::parse($request->checkin);
        $checkout = Carbon::parse($request->checkout);
        $nights   = (int) $checkin->diffInDays($checkout);

        $slotsByDate = $this->slotsByDate($room);
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

            $price  = $rts?->price !== null ? (float) $rts->price : $basePrice;
            $promos = $this->calculator->calculateForDate($rts, $price, $dateStr);
            $disc   = $price - $promos['final_price'];

            $subtotal           += $price;
            $totalPromoDiscount += $disc;

            $breakdown[] = [
                'date'               => $dateStr,
                'price'              => (int) $price,
                'final_price'        => (int) $promos['final_price'],
                'promotion_discount' => (int) $disc,
                'promotions'         => $promos['applied'],
                'checkin'            => $rts?->checkin  ?? $defCheckin,
                'checkout'           => $rts?->checkout ?? $defCheckout,
            ];

            $current->addDay();
        }

        $firstRts = $slotsByDate->get($checkin->format('Y-m-d'));
        $lastRts  = $slotsByDate->get($checkout->copy()->subDay()->format('Y-m-d'));

        return response()->json([
            'nights'             => $nights,
            'checkin_date'       => $checkin->format('Y-m-d'),
            'checkout_date'      => $checkout->format('Y-m-d'),
            'checkin_time'       => $firstRts?->checkin  ?? $defCheckin,
            'checkout_time'      => $lastRts?->checkout  ?? $defCheckout,
            'nights_breakdown'   => $breakdown,
            'subtotal'           => (int) $subtotal,
            'promotion_discount' => (int) $totalPromoDiscount,
            'total_after_promo'  => (int) max(0, $subtotal - $totalPromoDiscount),
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

    private function bookedDates(string $roomId, Carbon $from, Carbon $to): array
    {
        $items = OrderItem::where('product_id', $roomId)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkout_date', '>', $from)
            ->where('checkin_date', '<', $to->copy()->addDay())
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped', 'confirmed']))
            ->get(['checkin_date', 'checkout_date']);

        $booked  = [];
        foreach ($items as $item) {
            $night = Carbon::parse($item->checkin_date)->startOfDay();
            $end   = Carbon::parse($item->checkout_date)->startOfDay();
            while ($night->lt($end)) {
                $booked[] = $night->format('Y-m-d');
                $night->addDay();
            }
        }

        return array_unique($booked);
    }
}
