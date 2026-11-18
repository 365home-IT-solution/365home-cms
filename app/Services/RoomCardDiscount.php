<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\PriceBoards\StayPriceResolver;
use Illuminate\Support\Facades\Cache;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Giảm giá hiển thị trên card phòng (home / search / wishlist): giá sau giảm, giá gốc, % giảm.
 * Cùng nguồn với lúc đặt phòng (PromotionCalculator + bảng giá), không tính voucher khách.
 */
class RoomCardDiscount
{
    public function __construct(
        private readonly PromotionCalculator $calculator,
        private readonly StayPriceResolver $resolver,
    ) {
    }

    /**
     * Phòng theo ngày: giá thấp nhất trong 30 ngày tới so với giá gốc (cùng cách tính Flash Sale).
     *
     * @return array{amount: float, original_amount: float, discount_percent: int}|null
     */
    public function forDaily(Product $room): ?array
    {
        $base = (float) $room->price;
        if ($base <= 0) {
            return null;
        }

        $result = Cache::remember(
            'room_card_discount:daily:' . $room->id . ':' . $room->updated_at?->timestamp . ':' . now()->format('Y-m-d-H') . intdiv((int) now()->format('i'), 5),
            now()->addMinutes(5),
            fn () => $this->computeDaily($room, $base) ?? false
        );

        return $result ?: null;
    }

    /**
     * Phòng theo giờ: khung có giá sau giảm thấp nhất làm amount, giá gốc và % giảm của chính khung đó.
     *
     * @param  iterable<RoomTimeSlot>  $roomSlots  các room_time_slot hợp lệ (đã lọc khung giờ)
     * @return array{amount: float, original_amount: float, discount_percent: int}|null
     */
    public function forHourly(iterable $roomSlots): ?array
    {
        $today = now()->format('Y-m-d');
        $best  = null;

        foreach ($roomSlots as $rts) {
            if (! $rts->timeSlot || ! $rts->timeSlot->start_time || ! $rts->timeSlot->end_time) {
                continue;
            }

            $calc = $this->calculator->calculate($rts->loadMissing('promotions'), $today);
            $base = $calc['base_price'];
            $final = $calc['final_price'];
            if ($base <= 0 || $final >= $base) {
                continue;
            }

            if ($best === null || $final < $best['amount']) {
                $best = [
                    'amount'           => $final,
                    'original_amount'  => $base,
                    'discount_percent' => $this->percent($base, $final),
                ];
            }
        }

        return $best;
    }

    private function computeDaily(Product $room, float $base): ?array
    {
        $now   = now();
        $slots = RoomTimeSlot::where('room_id', $room->id)
            ->whereHas('timeSlot', fn ($q) => $q->where('type', 'date')
                ->whereBetween('label', [$now->format('Y-m-d'), $now->copy()->addDays(29)->format('Y-m-d')]))
            ->with(['timeSlot', 'promotions' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->keyBy(fn ($rts) => $rts->timeSlot?->label);

        $min = null;
        for ($day = $now->copy()->startOfDay(), $i = 0; $i < 30; $i++, $day->addDay()) {
            $date  = $day->format('Y-m-d');
            $rts   = $slots->get($date);
            $price = $rts?->price !== null ? (float) $rts->price : $this->resolver->nightPrice($room, $date, $base);
            $final = $this->calculator->calculateForDate($rts, $price, $date)['final_price'];
            $min   = $min === null ? $final : min($min, $final);
        }

        if ($min === null || $min >= $base) {
            return null;
        }

        return [
            'amount'           => (float) $min,
            'original_amount'  => $base,
            'discount_percent' => $this->percent($base, $min),
        ];
    }

    private function percent(float $original, float $final): int
    {
        return max(1, min(99, (int) round(($original - $final) / $original * 100)));
    }
}
