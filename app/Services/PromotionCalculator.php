<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Tính promotion cho 1 slot đặt phòng.
 *
 * Logic chuẩn — dùng chung cho cả API và Livewire để đảm bảo
 * kết quả giống nhau 100%.
 *
 * Thứ tự áp dụng:
 *   1. increase_fixed / increase_percentage  (tăng giá trước)
 *   2. fixed / percentage                    (giảm giá sau)
 */
class PromotionCalculator
{
    /**
     * Tính giá sau promotion cho 1 RoomTimeSlot vào một ngày cụ thể.
     *
     * @return array{
     *   base_price: float,
     *   increase_amount: float,
     *   promo_discount: float,
     *   final_price: float,
     *   applied: list<array{id:int,name:string,type:string,value:float,discount_amount:float}>
     * }
     */
    public function calculate(RoomTimeSlot $rts, string $bookingDate): array
    {
        $basePrice        = (float) $rts->price;
        $priceAfterInc    = $basePrice;
        $finalPrice       = $basePrice;
        $totalDiscount    = 0.0;
        $totalIncrease    = 0.0;
        $applied          = [];

        $slotStart = Carbon::parse("{$bookingDate} {$rts->timeSlot->start_time}");
        $slotEnd   = Carbon::parse("{$bookingDate} {$rts->timeSlot->end_time}");
        if ($slotEnd->lte($slotStart)) {
            $slotEnd->addDay(); // overnight slot
        }

        // Pass 1: tăng giá
        foreach ($rts->promotions as $promo) {
            if (! $this->isApplicable($promo, $slotStart, $slotEnd)) {
                continue;
            }

            $v = (float) $promo->value;

            if ($promo->type === 'increase_fixed') {
                $priceAfterInc += $v;
                $finalPrice    += $v;
                $totalIncrease += $v;
                $applied[]      = $this->entry($promo, $v);
            } elseif ($promo->type === 'increase_percentage') {
                $inc            = round($priceAfterInc * $v / 100);
                $priceAfterInc += $inc;
                $finalPrice    += $inc;
                $totalIncrease += $inc;
                $applied[]      = $this->entry($promo, $inc);
            }
        }

        // Pass 2: giảm giá
        foreach ($rts->promotions as $promo) {
            if (! $this->isApplicable($promo, $slotStart, $slotEnd)) {
                continue;
            }

            $v = (float) $promo->value;

            if ($promo->type === 'fixed') {
                $disc           = min($finalPrice, $v);
                $finalPrice    -= $disc;
                $totalDiscount += $disc;
                if (! $this->alreadyApplied($applied, $promo->id)) {
                    $applied[] = $this->entry($promo, $disc);
                }
            } elseif ($promo->type === 'percentage') {
                $disc           = round($finalPrice * $v / 100);
                $finalPrice    -= $disc;
                $totalDiscount += $disc;
                if (! $this->alreadyApplied($applied, $promo->id)) {
                    $applied[] = $this->entry($promo, $disc);
                }
            }
        }

        return [
            'base_price'      => $basePrice,
            'increase_amount' => $totalIncrease,
            'promo_discount'  => $totalDiscount,
            'final_price'     => max(0.0, $finalPrice),
            'applied'         => $applied,
        ];
    }

    /**
     * Kiểm tra promotion có áp dụng cho slot này không.
     * Dùng overlap: [slotStart, slotEnd] ∩ [promo.start_at, promo.end_at] ≠ ∅
     */
    public function isApplicable($promo, Carbon $slotStart, Carbon $slotEnd): bool
    {
        if (! $promo->is_active) {
            return false;
        }

        // Bắt buộc có start_at và end_at
        if (! $promo->start_at || ! $promo->end_at) {
            return false;
        }

        // Overlap check — so với thời gian slot thực tế, KHÔNG phải now()
        if (! ($slotStart->lt($promo->end_at) && $slotEnd->gt($promo->start_at))) {
            return false;
        }

        // excluded_dates
        $excludedDates = $promo->excluded_dates ?? [];
        if (is_string($excludedDates)) {
            $excludedDates = json_decode($excludedDates, true) ?? [];
        }
        foreach ($excludedDates as $ex) {
            $exDate = is_array($ex) ? ($ex['date'] ?? null) : $ex;
            if ($exDate && Carbon::parse($exDate)->isSameDay($slotStart)) {
                return false;
            }
        }

        // applicable_days_of_week
        $applicableDays = $promo->applicable_days_of_week ?? [];
        if (is_string($applicableDays)) {
            $applicableDays = json_decode($applicableDays, true) ?? [];
        }
        if (! empty($applicableDays) && ! in_array($slotStart->dayOfWeek, $applicableDays)) {
            return false;
        }

        // applicable_time_slots (giờ lặp lại trong ngày)
        $timeSlots = $promo->applicable_time_slots ?? [];
        if (is_string($timeSlots)) {
            $timeSlots = json_decode($timeSlots, true) ?? [];
        }
        if (! empty($timeSlots)) {
            $slotStartHi = $slotStart->format('H:i');
            $slotEndHi   = $slotEnd->format('H:i');
            $matched = false;
            foreach ($timeSlots as $range) {
                $pStart = substr($range['start'] ?? '', 0, 5);
                $pEnd   = substr($range['end']   ?? '', 0, 5);
                if ($pStart && $pEnd && $slotStartHi < $pEnd && $slotEndHi > $pStart) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function entry($promo, float $amount): array
    {
        return [
            'id'              => $promo->id,
            'name'            => $promo->name,
            'type'            => $promo->type,
            'value'           => (float) $promo->value,
            'discount_amount' => (int) $amount,
        ];
    }

    /**
     * Tính promotion cho phòng theo ngày (daily room) — không có start/end time.
     *
     * @return array{final_price: float, applied: list<array>}
     */
    public function calculateForDate(?RoomTimeSlot $rts, float $basePrice, string $date): array
    {
        if (! $rts || $rts->promotions->isEmpty()) {
            return ['final_price' => $basePrice, 'applied' => []];
        }

        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd   = Carbon::parse($date)->endOfDay();

        $priceAfterInc = $basePrice;
        $finalPrice    = $basePrice;
        $applied       = [];

        foreach ($rts->promotions as $promo) {
            if (! $this->isApplicableForDate($promo, $dayStart, $dayEnd, $date)) continue;
            $v = (float) $promo->value;
            if ($promo->type === 'increase_fixed') {
                $inc = $v;
            } elseif ($promo->type === 'increase_percentage') {
                $inc = round($priceAfterInc * $v / 100);
            } else {
                continue;
            }
            $priceAfterInc += $inc;
            $finalPrice    += $inc;
            $applied[]      = $this->entry($promo, $inc);
        }

        foreach ($rts->promotions as $promo) {
            if (! $this->isApplicableForDate($promo, $dayStart, $dayEnd, $date)) continue;
            $v = (float) $promo->value;
            if ($promo->type === 'fixed') {
                $disc = min($finalPrice, $v);
                $finalPrice -= $disc;
            } elseif ($promo->type === 'percentage') {
                $disc = round($priceAfterInc * $v / 100);
                $finalPrice -= $disc;
            } else {
                continue;
            }
            if (! $this->alreadyApplied($applied, $promo->id)) {
                $applied[] = $this->entry($promo, $disc);
            }
        }

        return ['final_price' => max(0.0, $finalPrice), 'applied' => $applied];
    }

    private function isApplicableForDate($promo, Carbon $dayStart, Carbon $dayEnd, string $date): bool
    {
        if (! $promo->is_active || ! $promo->start_at || ! $promo->end_at) return false;
        if (! ($dayStart->lt($promo->end_at) && $dayEnd->gt($promo->start_at))) return false;

        $excludedDates = $promo->excluded_dates ?? [];
        if (is_string($excludedDates)) $excludedDates = json_decode($excludedDates, true) ?? [];
        foreach ($excludedDates as $ex) {
            $exDate = is_array($ex) ? ($ex['date'] ?? null) : $ex;
            if ($exDate && Carbon::parse($exDate)->format('Y-m-d') === $date) return false;
        }

        $applicableDays = $promo->applicable_days_of_week ?? [];
        if (is_string($applicableDays)) $applicableDays = json_decode($applicableDays, true) ?? [];
        if (! empty($applicableDays) && ! in_array($dayStart->dayOfWeek, $applicableDays)) return false;

        return true;
    }

    private function alreadyApplied(array $applied, int $id): bool
    {
        foreach ($applied as $a) {
            if ($a['id'] === $id) return true;
        }
        return false;
    }
}
