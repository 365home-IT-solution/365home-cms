<?php

namespace Modules\VehicleRental\App\Services;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Modules\VehicleRental\App\Models\VehicleRatePlan;

/**
 * Tính tiền thuê xe theo gói giờ / gói ngày + km bao gồm. Thuần tính toán (không đụng DB) — nhận danh sách gói giá
 * (Eloquent hoặc hydrate lại từ snapshot của đơn) nên sửa giá trên CMS không làm đổi tiền của đơn đã đặt.
 *
 * Quy tắc:
 *  1. Thuê ngắn: chọn gói giờ NHỎ NHẤT đủ phủ thời lượng (cộng thời gian ân hạn của gói). Nếu gói giờ đắt hơn 1 ngày
 *     thì lấy giá ngày.
 *  2. Dưới 24h nhưng quá gói giờ lớn nhất: lấy gói giờ lớn nhất + giờ phụ trội; nếu tổng >= giá ngày thì tính 1 ngày.
 *  3. Từ 24h: tính theo số ngày 24h kể từ giờ nhận xe; phần dư <= ân hạn thì miễn, vượt thì tính theo giờ phụ trội,
 *     và nếu giờ phụ trội >= giá của ngày kế tiếp thì tính luôn thành 1 ngày.
 *  4. Giá cuối tuần chỉ áp cho từng ngày (gói ngày) rơi vào ngày trong config('vehiclerental.weekend_days').
 */
class VehiclePricingService
{
    /**
     * @param  Collection<int, VehicleRatePlan>  $plans
     * @return array{
     *   rental_amount:int, included_km:?int, extra_km_price:int, billed_minutes:int,
     *   lines:array<int, array{label:string, amount:int}>, plan_ids:array<int,int>
     * }
     */
    public function quote(Collection $plans, CarbonInterface $start, CarbonInterface $end): array
    {
        $minutes = $this->minutesBetween($start, $end);
        if ($minutes <= 0) {
            throw new DomainException('Thời gian trả xe phải sau thời gian nhận xe.');
        }

        $active = $plans->filter(fn (VehicleRatePlan $p) => $p->is_active !== false && $p->price > 0);
        $hourPlans = $active->where('unit', VehicleRatePlan::UNIT_HOUR)->sortBy('duration_hours')->values();
        $dayPlan = $active->where('unit', VehicleRatePlan::UNIT_DAY)->sortBy('sort_order')->first();

        if ($hourPlans->isEmpty() && ! $dayPlan) {
            throw new DomainException('Xe chưa có gói giá nào đang bật.');
        }

        // 1) Gói giờ nhỏ nhất đủ phủ.
        foreach ($hourPlans as $plan) {
            if ($minutes <= $plan->duration_hours * 60 + $plan->overtime_grace_minutes) {
                $price = $this->planPrice($plan, $start);
                if ($dayPlan && $price >= $this->planPrice($dayPlan, $start)) {
                    return $this->quoteDays($dayPlan, $start, 1, 0, $minutes);
                }

                return $this->result($price, $plan->included_km, $plan->extra_km_price, $minutes, [
                    ['label' => $plan->name, 'amount' => $price],
                ], [$plan->id]);
            }
        }

        // 2) Quá gói giờ lớn nhất nhưng chưa tới 24h.
        if ($minutes < 1440 || ! $dayPlan) {
            $candidate = $this->largestPackagePlusOvertime($hourPlans, $start, $minutes);
            if ($candidate && (! $dayPlan || $candidate['rental_amount'] < $this->planPrice($dayPlan, $start))) {
                return $candidate;
            }
            if (! $dayPlan) {
                throw new DomainException('Thời lượng thuê vượt quá các gói giờ và xe chưa có gói ngày.');
            }

            return $this->quoteDays($dayPlan, $start, 1, 0, $minutes);
        }

        // 3) Từ 24h: theo ngày + giờ phụ trội.
        $days = intdiv($minutes, 1440);
        $remainder = $minutes - $days * 1440;
        $overtimeHours = 0;
        if ($remainder > $dayPlan->overtime_grace_minutes) {
            $overtimeHours = (int) ceil(($remainder - $dayPlan->overtime_grace_minutes) / 60);
            $overtimeCost = $overtimeHours * $dayPlan->overtime_hour_price;
            $nextDayPrice = $this->planPrice($dayPlan, $start->copy()->addDays($days));
            if ($dayPlan->overtime_hour_price <= 0 || $overtimeCost >= $nextDayPrice) {
                $days++;
                $overtimeHours = 0;
            }
        }

        return $this->quoteDays($dayPlan, $start, $days, $overtimeHours, $minutes);
    }

    /** Gói theo km đang bán của xe (nếu có). */
    public function kmPlan(Collection $plans): ?VehicleRatePlan
    {
        return $plans->filter(fn (VehicleRatePlan $p) => $p->is_active !== false && $p->price > 0 && $p->unit === VehicleRatePlan::UNIT_KM)
            ->sortBy('sort_order')->first();
    }

    /** Số km mặc định gợi ý khi khách chưa nhập: km tối thiểu của gói, nếu không có thì 50. */
    public function defaultEstimatedKm(VehicleRatePlan $plan): int
    {
        return max(1, (int) ($plan->min_km ?: 50));
    }

    /** Tiền theo km: max(km, km tối thiểu) × đơn giá km. */
    public function kmAmount(VehicleRatePlan $plan, int $km): int
    {
        return max(max(0, $km), (int) $plan->min_km) * (int) $plan->price;
    }

    /**
     * Báo giá THEO KM (ước tính theo số km dự kiến). Tiền chốt theo km thực tế lúc trả xe — xem VehicleHandoverService::returnVehicle().
     *
     * @return array{rental_amount:int, included_km:null, extra_km_price:int, billed_minutes:int, lines:array<int, array{label:string, amount:int}>, plan_ids:array<int,int>, mode:string, price_per_km:int, min_km:?int, estimated_km:int}
     */
    public function quoteKm(Collection $plans, CarbonInterface $start, CarbonInterface $end, ?int $estimatedKm = null): array
    {
        $minutes = $this->minutesBetween($start, $end);
        if ($minutes <= 0) {
            throw new DomainException('Thời gian trả xe phải sau thời gian nhận xe.');
        }
        $plan = $this->kmPlan($plans);
        if (! $plan) {
            throw new DomainException('Xe này không có gói tính theo km.');
        }

        $km = max(1, $estimatedKm ?? $this->defaultEstimatedKm($plan));
        $billedKm = max($km, (int) $plan->min_km);

        return [
            'rental_amount'  => $this->kmAmount($plan, $km),
            'included_km'    => null,
            'extra_km_price' => 0,
            'billed_minutes' => $minutes,
            'lines'          => [['label' => 'Ước tính ' . number_format($billedKm, 0, ',', '.') . ' km × ' . number_format((int) $plan->price, 0, ',', '.') . 'đ', 'amount' => $this->kmAmount($plan, $km)]],
            'plan_ids'       => [$plan->id],
            'mode'           => 'km',
            'price_per_km'   => (int) $plan->price,
            'min_km'         => $plan->min_km ? (int) $plan->min_km : null,
            'estimated_km'   => $km,
        ];
    }

    /**
     * Tiền trễ giờ: chênh lệch giữa báo giá theo giờ trả THỰC TẾ và theo giờ trả dự kiến (0 nếu trả đúng/sớm).
     */
    public function lateCharge(Collection $plans, CarbonInterface $start, CarbonInterface $plannedEnd, CarbonInterface $actualEnd): int
    {
        if ($actualEnd->lessThanOrEqualTo($plannedEnd)) {
            return 0;
        }

        $planned = $this->quote($plans, $start, $plannedEnd)['rental_amount'];
        $actual = $this->quote($plans, $start, $actualEnd)['rental_amount'];

        return max(0, $actual - $planned);
    }

    /**
     * Tiền km vượt. $quote là kết quả của quote() (đã chứa km bao gồm + đơn giá km vượt).
     *
     * @return array{extra_km:int, amount:int}
     */
    public function kmOverage(array $quote, int $kmDriven): array
    {
        $included = $quote['included_km'];
        $extra = $included === null ? 0 : max(0, $kmDriven - $included);

        return ['extra_km' => $extra, 'amount' => $extra * (int) $quote['extra_km_price']];
    }

    /** Lưu gói giá vào snapshot của đơn (mảng thuần, không phụ thuộc Eloquent). */
    public function snapshot(Collection $plans): array
    {
        return $plans->map(fn (VehicleRatePlan $p) => $p->only([
            'id', 'name', 'unit', 'duration_hours', 'price', 'weekend_price', 'included_km', 'extra_km_price', 'min_km',
            'overtime_grace_minutes', 'overtime_hour_price', 'is_active', 'sort_order',
        ]))->values()->all();
    }

    /** Dựng lại gói giá từ snapshot để tính lại (trễ giờ, km vượt) đúng theo giá lúc đặt. */
    public function hydrate(array $snapshot): Collection
    {
        return collect($snapshot)->map(fn (array $row) => (new VehicleRatePlan())->forceFill($row));
    }

    private function largestPackagePlusOvertime(Collection $hourPlans, CarbonInterface $start, int $minutes): ?array
    {
        $plan = $hourPlans->filter(fn (VehicleRatePlan $p) => $p->duration_hours * 60 <= $minutes)->last();
        if (! $plan || $plan->overtime_hour_price <= 0) {
            return null;
        }

        $extraMinutes = $minutes - $plan->duration_hours * 60;
        $hours = $extraMinutes > $plan->overtime_grace_minutes
            ? (int) ceil(($extraMinutes - $plan->overtime_grace_minutes) / 60)
            : 0;
        $base = $this->planPrice($plan, $start);
        $lines = [['label' => $plan->name, 'amount' => $base]];
        $total = $base;
        if ($hours > 0) {
            $over = $hours * $plan->overtime_hour_price;
            $lines[] = ['label' => "Phụ trội {$hours} giờ", 'amount' => $over];
            $total += $over;
        }

        return $this->result($total, $plan->included_km, $plan->extra_km_price, $minutes, $lines, [$plan->id]);
    }

    private function quoteDays(VehicleRatePlan $plan, CarbonInterface $start, int $days, int $overtimeHours, int $minutes): array
    {
        $lines = [];
        $total = 0;
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $price = $this->planPrice($plan, $day);
            $total += $price;
            $lines[] = ['label' => $plan->name . ' - ' . $day->format('d/m'), 'amount' => $price];
        }
        if ($overtimeHours > 0) {
            $over = $overtimeHours * $plan->overtime_hour_price;
            $total += $over;
            $lines[] = ['label' => "Phụ trội {$overtimeHours} giờ", 'amount' => $over];
        }

        $included = $plan->included_km === null ? null : $plan->included_km * $days;

        return $this->result($total, $included, $plan->extra_km_price, $minutes, $lines, [$plan->id]);
    }

    private function planPrice(VehicleRatePlan $plan, CarbonInterface $day): int
    {
        if ($plan->weekend_price !== null && $plan->unit === VehicleRatePlan::UNIT_DAY
            && in_array($day->dayOfWeek, (array) config('vehiclerental.weekend_days', [6, 0]), true)) {
            return (int) $plan->weekend_price;
        }

        return (int) $plan->price;
    }

    private function minutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        return intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    }

    private function result(int $amount, ?int $includedKm, int $extraKmPrice, int $minutes, array $lines, array $planIds): array
    {
        return [
            'rental_amount'  => $amount,
            'included_km'    => $includedKm,
            'extra_km_price' => $extraKmPrice,
            'billed_minutes' => $minutes,
            'lines'          => $lines,
            'plan_ids'       => $planIds,
        ];
    }
}
