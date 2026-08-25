<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Modules\Product\App\Models\Product;

/**
 * Trích xuất từ BookingController (checkFullDayBooking/applyFullBookingDiscount/applyBulkDiscount/
 * applyPromotions/applyDailyPromotions) — không sửa file gốc. Dùng để áp đúng các điều kiện giảm
 * giá cấu hình ở trang SettingBook (full_booking_discount, bulk_discount_rules, khuyến mãi theo
 * khung giờ/ngày) cho phần khách đặt thêm qua OrderExtraBookingService, khớp 100% với cách tính
 * lúc đặt đơn mới — không coupon (API /extra không nhận coupon_codes).
 */
class RoomDiscountCalculator
{
    public function checkFullDayBooking(array $slotSummary, Product $room): bool
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

    public function applyFullBookingDiscount(float $amount, Product $room): array
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

    public function applyBulkDiscount(int $slotCount, Product $room, float $amount): array
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
            'label'           => "Đặt {$slotCount} khung giờ",
            'rate'            => $matched['discount'] ?? 0,
            'discount_amount' => $discount,
        ];

        return [$discount, $info];
    }

    public function applyPromotions(Collection $rtsCollection, array $slotSummary = []): array
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
                foreach ($applied as $i2 => $a) {
                    if ($a['id'] === $entry['id']) {
                        $applied[$i2]['discount_amount'] += $entry['discount_amount'];
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

    public function applyDailyPromotions(Collection $rtsCollection, array $nightSummary): array
    {
        $calculator    = new PromotionCalculator();
        $nightPriceMap = collect($nightSummary)->pluck('price', 'date');

        $totalDiscount = 0;
        $applied       = [];

        foreach ($rtsCollection as $rts) {
            $date = $rts->timeSlot?->label;
            if (! $date || ! $nightPriceMap->has($date)) {
                continue;
            }

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
}
